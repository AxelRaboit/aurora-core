<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Adapter;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\Core\Exception\Http\NetworkException;
use AsyncAws\Core\Exception\Http\ServerException;
use AsyncAws\S3\Result\ListObjectsV2Output;
use AsyncAws\S3\ValueObject\ObjectIdentifier;
use AsyncAws\SimpleS3\SimpleS3Client;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Exception\StorageException;
use Aurora\Core\Storage\R2\R2ConfigurationProviderInterface;
use Aurora\Core\Storage\R2\S3ClientFactory;
use Aurora\Core\Storage\StoredContentType;
use Aurora\Core\Storage\Workspace\LocalPathAware;
use DateTimeImmutable;
use Generator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function sprintf;

/**
 * Cloudflare R2, through its S3-compatible API.
 *
 * Reads the same contract as the local disk and is checked against the same
 * expectations, with one asymmetry it can never close: it is not
 * {@see LocalPathAware}. Nothing here is a file
 * on this machine, so image and PDF tooling gets a temporary copy through the
 * workspace rather than the object itself.
 *
 * **On what costs money.** Egress is free on R2; requests are not. So
 * `list()` reads metadata straight out of the listing rather than asking
 * again per object, `deleteMany()` batches, and nothing here calls `exists()`
 * on the caller's behalf as a defensive reflex. A thousand orphans cost one
 * listing and one delete, not two thousand round trips.
 *
 * **On Cloudflare compressing things.** R2 gzips compressible content types
 * as it serves them, and a gzipped response has no `Content-Length` and a weak
 * ETag. Metadata therefore never comes from a HEAD here; see {@see stat()}.
 *
 * **On retries.** Only the failures that can plausibly succeed on a second
 * attempt are retried: a network drop, or a 5xx. A refused signature or a
 * missing bucket is retried never, because the only thing repetition adds
 * there is delay and another billed request.
 */
final readonly class R2StorageAdapter implements StorageAdapterInterface
{
    /** Attempts in total, not retries after the first. */
    private const int MAX_ATTEMPTS = 3;

    /** Base backoff, doubled per attempt. */
    private const int RETRY_BASE_DELAY_MS = 200;

    /** S3 refuses a delete batch larger than this. */
    private const int DELETE_BATCH_SIZE = 1000;

    public function __construct(
        private S3ClientFactory $clientFactory,
        private R2ConfigurationProviderInterface $configurationProvider,
        private Filesystem $filesystem = new Filesystem(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function disk(): StorageDiskEnum
    {
        return StorageDiskEnum::R2;
    }

    /**
     * Whether an administrator has finished filling the four fields.
     *
     * Read off the configuration rather than by reaching for the bucket: a
     * caller sweeping the adapters for a file must not pay a request, or a
     * timeout, to learn that this one was never set up.
     */
    public function isReady(): bool
    {
        return $this->configurationProvider->current()->isComplete();
    }

    public function writeFromLocalFile(string $key, string $sourceAbsolutePath): void
    {
        $handle = @fopen($sourceAbsolutePath, 'r');

        if (false === $handle) {
            throw StorageException::writeFailed($key, sprintf('source "%s" is not readable', $sourceAbsolutePath));
        }

        try {
            $this->attempt(
                'write',
                $key,
                fn () => $this->client()->upload($this->configurationProvider->current()->bucket, $key, $handle, [
                    'ContentType' => $this->guessContentType($sourceAbsolutePath, $key),
                ]),
            );
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function write(string $key, string $contents): void
    {
        $this->attempt(
            'write',
            $key,
            fn () => $this->client()->upload($this->configurationProvider->current()->bucket, $key, $contents, [
                'ContentType' => $this->contentTypeForKey($key),
            ]),
        );
    }

    public function read(string $key): string
    {
        return $this->attempt('read', $key, function () use ($key): string {
            try {
                return $this->client()->download($this->configurationProvider->current()->bucket, $key)->getContentAsString();
            } catch (ClientException $clientException) {
                throw $this->isNotFound($clientException) ? StorageException::missingKey($key) : $clientException;
            }
        });
    }

    public function readStream(string $key): Generator
    {
        // The attempt wrapper cannot cover the iteration itself: a generator
        // that failed half way cannot be replayed from the start without
        // sending the caller the first chunks twice. Only opening is retried.
        $stream = $this->attempt('read', $key, function () use ($key) {
            try {
                return $this->client()->download($this->configurationProvider->current()->bucket, $key);
            } catch (ClientException $clientException) {
                throw $this->isNotFound($clientException) ? StorageException::missingKey($key) : $clientException;
            }
        });

        yield from $stream->getChunks();
    }

    public function copyToLocalFile(string $key, string $targetAbsolutePath): void
    {
        $this->filesystem->mkdir(dirname($targetAbsolutePath));

        $this->attempt('read', $key, function () use ($key, $targetAbsolutePath): void {
            try {
                $stream = $this->client()->download($this->configurationProvider->current()->bucket, $key);
            } catch (ClientException $clientException) {
                throw $this->isNotFound($clientException) ? StorageException::missingKey($key) : $clientException;
            }

            $target = @fopen($targetAbsolutePath, 'w');

            if (false === $target) {
                throw StorageException::readFailed($key, sprintf('cannot write "%s"', $targetAbsolutePath));
            }

            try {
                // Chunked rather than getContentAsString(): a video does not
                // need to exist twice, once in memory and once on disk.
                foreach ($stream->getChunks() as $chunk) {
                    fwrite($target, $chunk);
                }
            } finally {
                fclose($target);
            }
        });
    }

    public function exists(string $key): bool
    {
        return $this->attempt('exists', $key, fn (): bool => $this->client()->has($this->configurationProvider->current()->bucket, $key));
    }

    public function delete(string $key): void
    {
        // Already idempotent at the protocol level: S3 answers 204 for a key
        // that was never there.
        $this->attempt('delete', $key, function () use ($key): void {
            $this->client()->deleteObject(['Bucket' => $this->configurationProvider->current()->bucket, 'Key' => $key])->resolve();
        });
    }

    public function deleteMany(array $keys): void
    {
        if ([] === $keys) {
            return;
        }

        foreach (array_chunk($keys, self::DELETE_BATCH_SIZE) as $batch) {
            $this->attempt('deleteMany', sprintf('%d keys', count($batch)), function () use ($batch): void {
                $this->client()->deleteObjects([
                    'Bucket' => $this->configurationProvider->current()->bucket,
                    'Delete' => [
                        'Objects' => array_map(
                            static fn (string $key): ObjectIdentifier => new ObjectIdentifier(['Key' => $key]),
                            $batch,
                        ),
                        // Errors still surface; what is suppressed is the list
                        // of the ones that worked, which nobody reads.
                        'Quiet' => true,
                    ],
                ])->resolve();
            });
        }
    }

    public function list(string $prefix): Generator
    {
        $result = $this->attempt(
            'list',
            $prefix,
            fn (): ListObjectsV2Output => $this->client()->listObjectsV2([
                'Bucket' => $this->configurationProvider->current()->bucket,
                'Prefix' => '' === $prefix ? null : mb_rtrim($prefix, '/').'/',
            ]),
        );

        // getContents() walks the pages itself, so this stays lazy: a caller
        // that stops early has not paid for the pages it never looked at.
        foreach ($result->getContents() as $object) {
            $key = $object->getKey();

            if (null === $key) {
                continue;
            }

            yield new StoredObject(
                key: $key,
                size: (int) $object->getSize(),
                lastModifiedAt: $object->getLastModified() ?? new DateTimeImmutable(),
                checksum: $this->normaliseEtag($object->getEtag()),
            );
        }
    }

    /**
     * Answered from a listing scoped to the one key, not from a HEAD.
     *
     * Cloudflare gzips compressible content types on the fly, and a gzipped
     * response carries no `Content-Length` and a weak ETag. So `HeadObject`
     * reports no size at all for a `text/plain` object while reporting the
     * right one for a PNG, which is the kind of difference that shows up as a
     * corrupted size column months later rather than as an error now.
     *
     * `ListObjectsV2` reports what the object weighs in the bucket rather than
     * what the response weighs on the wire, so it is right either way. It
     * costs a class A operation where a HEAD costs a class B, roughly twelve
     * times more, which is a price worth paying on a path nothing calls in a
     * loop. Code that needs sizes for many objects should be listing them, and
     * that is what `list()` is for.
     */
    public function stat(string $key): ?StoredObject
    {
        $result = $this->attempt('stat', $key, fn (): ListObjectsV2Output => $this->client()->listObjectsV2([
            'Bucket' => $this->configurationProvider->current()->bucket,
            'Prefix' => $key,
            'MaxKeys' => 1,
        ]));

        foreach ($result->getContents(true) as $object) {
            // A prefix match is not an exact match: `note.txt` also finds
            // `note.txt.bak`.
            if ($object->getKey() !== $key) {
                continue;
            }

            return new StoredObject(
                key: $key,
                size: (int) $object->getSize(),
                lastModifiedAt: $object->getLastModified() ?? new DateTimeImmutable(),
                checksum: $this->normaliseEtag($object->getEtag()),
            );
        }

        return null;
    }

    /**
     * The address a browser can fetch directly, when a hostname was mapped
     * onto the bucket.
     *
     * Null otherwise, and deliberately not a guess: R2's own
     * `*.r2.cloudflarestorage.com` endpoint refuses unsigned reads, so
     * returning it would hand callers a URL that answers 401 to every
     * visitor. A null says "ask for a presigned one instead", which is a
     * thing callers can act on.
     */
    public function publicUrl(string $key): ?string
    {
        $baseUrl = $this->configurationProvider->current()->publicBaseUrl;

        if (null === $baseUrl) {
            return null;
        }

        return sprintf('%s/%s', $baseUrl, implode('/', array_map(rawurlencode(...), explode('/', $key))));
    }

    /**
     * A URL that works for a short while and then does not.
     *
     * Signed here, with no request to Cloudflare: presigning is arithmetic
     * over the credentials, so this costs nothing and can be done per page
     * render.
     */
    public function temporaryUrl(string $key, int $ttlSeconds = 300): string
    {
        return $this->client()->getPresignedUrl(
            $this->configurationProvider->current()->bucket,
            $key,
            new DateTimeImmutable(sprintf('+%d seconds', max(1, $ttlSeconds))),
        );
    }

    private function client(): SimpleS3Client
    {
        return $this->clientFactory->create();
    }

    /**
     * Runs an operation, retrying only what a retry could fix.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function attempt(string $operation, string $subject, callable $work): mixed
    {
        $attempt = 1;

        while (true) {
            try {
                return $work();
            } catch (StorageException $exception) {
                // Ours, and already meaningful: a missing key is an answer,
                // not a transport failure.
                throw $exception;
            } catch (NetworkException|ServerException $exception) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    $this->logger->error('R2 {operation} failed after {attempts} attempts on {subject}: {reason}', [
                        'operation' => $operation,
                        'attempts' => $attempt,
                        'subject' => $subject,
                        'reason' => $exception->getMessage(),
                    ]);

                    throw $this->wrap($operation, $subject, $exception);
                }

                // Logged at debug: a retried blip that then succeeded is not
                // news, and logging it at warning trains people to ignore
                // warnings.
                $this->logger->debug('R2 {operation} on {subject} failed, retrying', [
                    'operation' => $operation,
                    'subject' => $subject,
                    'attempt' => $attempt,
                ]);

                usleep(self::RETRY_BASE_DELAY_MS * 1000 * 2 ** ($attempt - 1));
                ++$attempt;
            } catch (Throwable $exception) {
                throw $this->wrap($operation, $subject, $exception);
            }
        }
    }

    private function wrap(string $operation, string $subject, Throwable $exception): StorageException
    {
        return new StorageException(
            sprintf('R2 %s failed on "%s": %s', $operation, $subject, $exception->getMessage()),
            previous: $exception,
        );
    }

    private function isNotFound(HttpException $exception): bool
    {
        return 404 === $exception->getResponse()->getStatusCode();
    }

    /**
     * S3 wraps the etag in quotes, and a multipart upload suffixes it with
     * `-<parts>`, which makes it not an MD5 of anything. Kept as an opaque
     * comparison token either way, with the quotes off.
     */
    private function normaliseEtag(?string $etag): ?string
    {
        if (null === $etag || '' === $etag) {
            return null;
        }

        return mb_trim($etag, '"');
    }

    private function guessContentType(string $absolutePath, string $key): string
    {
        $detected = @mime_content_type($absolutePath);

        return false === $detected || '' === $detected
            ? $this->contentTypeForKey($key)
            : $detected;
    }

    /**
     * Type from the extension, for the calls that hold bytes rather than a
     * file. Wrong beats absent: an object stored without one is served as a
     * download, which for an image on a page means it does not render.
     *
     * Shared with the serve endpoint, which asks the same question of the
     * same key when it streams an object back through PHP.
     */
    private function contentTypeForKey(string $key): string
    {
        return StoredContentType::forKey($key);
    }
}
