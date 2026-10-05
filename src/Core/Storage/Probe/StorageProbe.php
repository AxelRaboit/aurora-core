<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Probe;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Exception\StorageException;
use DateTimeImmutable;
use Throwable;

use function sprintf;

/**
 * Proves a storage backend works, by using it.
 *
 * Not a configuration check. Reading four non-empty strings out of a settings
 * table proves nothing, and the failure everyone actually hits is a token with
 * read-only rights or scoped to the wrong bucket, which looks perfectly
 * configured right up until the first write.
 *
 * So it writes a witness object, reads it back, compares the bytes, confirms
 * the listing sees it, and deletes it. Each step is reported separately,
 * because "it does not work" is not actionable and "write succeeded, read
 * failed" is.
 *
 * One service for two surfaces: the console command an operator runs and the
 * button an administrator presses. They must not be able to disagree about
 * whether a backend works.
 */
final readonly class StorageProbe
{
    /**
     * Where witnesses go. Its own prefix so nothing here can collide with a
     * real file, and so a leftover is recognisable for what it is.
     */
    public const string WITNESS_PREFIX = '_doctor';

    public function run(StorageAdapterInterface $adapter): StorageProbeResult
    {
        $key = sprintf(
            '%s/%s-%s.txt',
            self::WITNESS_PREFIX,
            new DateTimeImmutable()->format('Ymd-His'),
            bin2hex(random_bytes(6)),
        );
        $contents = sprintf('aurora storage probe %s', bin2hex(random_bytes(16)));
        $size = mb_strlen($contents, '8bit');

        $steps = [];
        $error = null;

        foreach ([
            'write' => static fn () => $adapter->write($key, $contents),
            'read_back' => static function () use ($adapter, $key, $contents): void {
                if ($adapter->read($key) !== $contents) {
                    throw new StorageException('the bytes read back differ from the bytes written');
                }
            },
            'exists' => static function () use ($adapter, $key): void {
                if (!$adapter->exists($key)) {
                    throw new StorageException('the object was written but exists() says no');
                }
            },
            'metadata' => static function () use ($adapter, $key, $size): void {
                $stored = $adapter->stat($key);

                if (!$stored instanceof StoredObject) {
                    throw new StorageException('stat() found nothing');
                }

                if ($stored->size !== $size) {
                    throw new StorageException(sprintf('stat() reports %d bytes, %d were written', $stored->size, $size));
                }
            },
            'listing' => static function () use ($adapter, $key, $size): void {
                foreach ($adapter->list(self::WITNESS_PREFIX) as $object) {
                    if ($object->key !== $key) {
                        continue;
                    }

                    if ($object->size !== $size) {
                        throw new StorageException('the listing reports a size that does not match');
                    }

                    return;
                }

                throw new StorageException('the object is not in the listing');
            },
        ] as $label => $step) {
            try {
                $step();
                $steps[] = new StorageProbeStep($label, true);
            } catch (Throwable $throwable) {
                $error = $throwable->getMessage();
                $steps[] = new StorageProbeStep($label, false, $error);
                break;
            }
        }

        // Always attempted, whichever step failed: a backend that can write but
        // not delete has to be told so, and a run that died half way must not
        // leave litter for the next one to puzzle over.
        try {
            $adapter->delete($key);
            $steps[] = new StorageProbeStep('cleanup', true);
        } catch (Throwable $throwable) {
            $error ??= $throwable->getMessage();
            $steps[] = new StorageProbeStep('cleanup', false, $throwable->getMessage());
        }

        $ok = array_reduce($steps, static fn (bool $carry, StorageProbeStep $step): bool => $carry && $step->ok, true);

        return new StorageProbeResult(
            ok: $ok,
            steps: $steps,
            error: $ok ? null : $error,
            hint: $ok ? null : $this->hint($adapter->disk(), (string) $error),
        );
    }

    /**
     * Turns the common failures into the thing to go and change.
     *
     * Every one of these cost somebody an evening, and the message the API
     * returns for them names none of the causes. Returned as a translation key
     * so the settings tab can say it in the reader's language.
     */
    private function hint(StorageDiskEnum $disk, string $message): ?string
    {
        if (StorageDiskEnum::R2 !== $disk) {
            return null;
        }

        $message = mb_strtolower($message);

        return match (true) {
            str_contains($message, 'not configured') => 'suite.settings.storage.hints.incomplete',
            str_contains($message, 'signature') => 'suite.settings.storage.hints.signature',
            str_contains($message, 'access denied'), str_contains($message, '403') => 'suite.settings.storage.hints.denied',
            str_contains($message, 'nosuchbucket'), str_contains($message, '404') => 'suite.settings.storage.hints.no_bucket',
            str_contains($message, 'could not resolve'), str_contains($message, 'timed out') => 'suite.settings.storage.hints.unreachable',
            default => null,
        };
    }
}
