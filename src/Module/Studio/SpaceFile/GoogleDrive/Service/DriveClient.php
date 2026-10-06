<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function array_chunk;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function usort;

/**
 * Reads the Drive folder a client shared with the service account.
 *
 * **Nothing is copied.** Drive stays the source: Aurora reads the file from
 * Google when someone asks for it and serves it under its own address. The
 * space's client, who has no Google account, so sees the file without ever
 * talking to Google; and removing the file from the shared folder removes it
 * from the space, which is the behaviour expected of a shared folder rather
 * than of a copy that ages.
 *
 * **Read-only, and Google is told so.** The token is requested for the
 * `drive.readonly` scope: even if someone later wrote a write request by
 * mistake, Google would refuse it. An integration that cannot write is an
 * integration that cannot break a client's Drive.
 *
 * **The token is cached.** Google issues one for an hour; asking again on
 * every display would add a round trip to every page for nothing. Kept for
 * fifty minutes, which leaves a ten-minute margin before the real expiry - a
 * clock that drifts by two minutes must not cause a refusal in the middle of
 * a visit.
 *
 * Failures become an empty list or a null rather than an exception: a folder
 * that cannot be reached is a screen that offers nothing, not a 500 error in
 * the middle of a customer space.
 */
final readonly class DriveClient
{
    private const string SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    private const string FILES_URI = 'https://www.googleapis.com/drive/v3/files';

    private const int TIMEOUT_SECONDS = 15;

    /** Fifty minutes out of the sixty Google grants. */
    private const int TOKEN_TTL_SECONDS = 3000;

    /**
     * What a folder can legitimately put on screen. Beyond that, it is no
     * longer a list one browses and the shared folder was too wide.
     */
    private const int MAX_FILES = 200;

    /**
     * How deep the descent goes. Five levels cover any reasonable filing, and
     * the limit exists mostly because a circular shortcut in a Drive would
     * make the descent run forever.
     */
    private const int MAX_DEPTH = 5;

    /**
     * How many folders fit in a single request. The `q` clause has a maximum
     * length, and forty parents fit in it easily.
     */
    private const int PARENTS_PER_QUERY = 40;

    private const string FOLDER_MIME = 'application/vnd.google-apps.folder';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * Everything the folder holds, subfolders included.
     *
     * **One call per level, not per folder.** Google accepts several parents
     * in the same request: a three-level tree therefore costs three calls
     * whatever the number of folders it holds. Going down folder by folder
     * would have made one request each, and a page that waits for thirty round
     * trips is no longer a page.
     *
     * **The path travels with the file.** A flat list of forty files that does
     * not say where they come from would be less readable than the tree it
     * replaces; so `path` carries "Contrats/2026", empty at the root, and the
     * screen uses it to place each file.
     *
     * Two limits. The depth, because a circular shortcut in a Drive would make
     * this descent run forever. And the number of files, because beyond it this
     * is no longer a list one scans with the eye - the shared folder was too
     * wide, and that is fixed in Drive.
     *
     * @return list<array{id: string, name: string, path: string, mimeType: string, size: int|null, modifiedAt: string|null, thumbnail: string|null}>
     */
    public function files(GoogleServiceAccount $account, string $folderId): array
    {
        $files = [];
        $level = [$folderId => ''];
        $seen = [$folderId => true];

        for ($depth = 0; $depth < self::MAX_DEPTH && [] !== $level; ++$depth) {
            $next = [];

            foreach (array_chunk($level, self::PARENTS_PER_QUERY, preserve_keys: true) as $chunk) {
                foreach ($this->children($account, array_keys($chunk)) as $row) {
                    $parentPath = $this->parentPathOf($row, $chunk);

                    if (self::FOLDER_MIME === $row['mimeType']) {
                        // A shortcut can bring back a folder already seen, and
                        // a folder seen twice is a loop.
                        if (!isset($seen[$row['id']])) {
                            $seen[$row['id']] = true;
                            $next[$row['id']] = '' === $parentPath ? $row['name'] : $parentPath.'/'.$row['name'];
                        }

                        continue;
                    }

                    unset($row['parents']);
                    $files[] = [...$row, 'path' => $parentPath];

                    if (self::MAX_FILES === count($files)) {
                        return $this->newestFirst($files);
                    }
                }
            }

            $level = $next;
        }

        return $this->newestFirst($files);
    }

    /**
     * Whether this file is one of those the folder shows.
     *
     * **The service account reads every folder shared with it**, not only this
     * space's: relaying an id without asking this served another client's
     * Drive to whoever guessed one. Asked of the list itself, not of the
     * file's parents: what gets relayed is exactly what the screen offers, same
     * depth and count limits, trash excluded.
     */
    public function contains(GoogleServiceAccount $account, string $folderId, string $fileId): bool
    {
        return array_any($this->files($account, $folderId), fn ($file): bool => $file['id'] === $fileId);
    }

    /**
     * The direct children of a batch of folders, in one request.
     *
     * @param list<string> $parentIds
     *
     * @return list<array{id: string, name: string, mimeType: string, size: int|null, modifiedAt: string|null, parents: list<string>, thumbnail: string|null}>
     */
    private function children(GoogleServiceAccount $account, array $parentIds): array
    {
        $clauses = array_map(static fn (string $id): string => sprintf("'%s' in parents", $id), $parentIds);

        $payload = $this->get($account, self::FILES_URI, [
            // `trashed = false` explicitly: a Drive's trash stays in the folder
            // and would come back out as a live file.
            'q' => '('.implode(' or ', $clauses).') and trashed = false',
            // `parents` is what tells which folder of the batch each row comes
            // from, and so rebuilds its path.
            'fields' => 'files(id,name,mimeType,size,modifiedTime,parents,thumbnailLink)',
            'pageSize' => self::MAX_FILES,
            // A folder shared from a shared Drive is not visible without this,
            // and the symptom is an empty list with no error.
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]);

        if (null === $payload) {
            return [];
        }

        $rows = [];

        foreach ((array) ($payload['files'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_string($id)) {
                continue;
            }

            if (!is_string($name)) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'name' => $name,
                'mimeType' => is_string($row['mimeType'] ?? null) ? $row['mimeType'] : 'application/octet-stream',
                // Google returns the size as a string, and leaves it out for
                // its own formats - a Google Doc has no bytes until it is
                // exported.
                'size' => isset($row['size']) ? (int) $row['size'] : null,
                'modifiedAt' => is_string($row['modifiedTime'] ?? null) ? $row['modifiedTime'] : null,
                'parents' => array_values(array_filter((array) ($row['parents'] ?? []), is_string(...))),
                // **Served by Google's CDN, without authentication** -
                // measured: two hundred and twenty pixels, under a kilobyte,
                // and a `200` without a single header. So it travels to the
                // browser instead of being relayed, which saves one call per
                // thumbnail shown. The trade-off is stated where the image is
                // placed: the client's browser talks to Google for it, which
                // gates no access but is worth knowing. Absent for what Google
                // cannot make an image of.
                'thumbnail' => is_string($row['thumbnailLink'] ?? null) ? $row['thumbnailLink'] : null,
            ];
        }

        return $rows;
    }

    /**
     * The path of the folder this row comes from, among those of the batch.
     *
     * A file can have several parents in a Drive; the first one that belongs
     * to the queried batch is kept, because it is the one that brought it up.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $chunk
     */
    private function parentPathOf(array $row, array $chunk): string
    {
        foreach ((array) ($row['parents'] ?? []) as $parent) {
            if (is_string($parent) && isset($chunk[$parent])) {
                return $chunk[$parent];
            }
        }

        return '';
    }

    /**
     * A shared folder is read by what just arrived in it, not in alphabetical
     * order.
     *
     * @param list<array<string, mixed>> $files
     *
     * @return list<array<string, mixed>>
     */
    private function newestFirst(array $files): array
    {
        usort($files, static fn (array $left, array $right): int => ($right['modifiedAt'] ?? '') <=> ($left['modifiedAt'] ?? ''));

        return $files;
    }

    /**
     * The file's name, and nothing else.
     *
     * **Google does not give it with the content.** Its response to
     * `alt=media` does carry a `Content-Disposition: attachment`, but without
     * `filename`: relayed as is, it would land "1BxY_…Kp3" in the client's
     * downloads folder. So the name is asked for separately.
     *
     * **And it is asked of Google, never of the browser.** Letting it travel in
     * the address would mean writing a header from what a visitor sends; here
     * the only possible name is the one the shared file really carries.
     *
     * @return array{name: string, mimeType: string}|null
     */
    public function metadata(GoogleServiceAccount $account, string $fileId): ?array
    {
        $payload = $this->get($account, self::FILES_URI.'/'.$fileId, [
            'fields' => 'name,mimeType',
            'supportsAllDrives' => 'true',
        ]);

        if (null === $payload || !is_string($payload['name'] ?? null)) {
            return null;
        }

        return [
            'name' => $payload['name'],
            'mimeType' => is_string($payload['mimeType'] ?? null) ? $payload['mimeType'] : '',
        ];
    }

    /**
     * The file itself, as a stream.
     *
     * Returned as is so the caller relays it without loading it into memory: a
     * shared folder holds videos and PDFs of several tens of megabytes, and
     * putting them in a PHP string to spit them out afterwards would bring the
     * server down on the first big file.
     *
     * Null when Google refuses - the file was removed from sharing, or
     * deleted. The caller turns it into a message, not an error.
     */
    public function download(GoogleServiceAccount $account, string $fileId): ?ResponseInterface
    {
        $token = $this->token($account);

        if (null === $token) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::FILES_URI.'/'.$fileId, [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'query' => ['alt' => 'media', 'supportsAllDrives' => 'true'],
                'timeout' => self::TIMEOUT_SECONDS,
                'buffer' => false,
            ]);

            // Read now: otherwise the caller would discover the refusal in the
            // middle of the stream, once the headers were already sent.
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            return $response;
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive download failed.', [
                'fileId' => $fileId,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The body of a download, chunk by chunk.
     *
     * Placed here rather than in the caller so it does not have to know the
     * HTTP client: it receives an iterable of strings and sends them on.
     *
     * @return iterable<string>
     */
    public function stream(ResponseInterface $response): iterable
    {
        foreach ($this->httpClient->stream($response) as $chunk) {
            yield $chunk->getContent();
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>|null
     */
    private function get(GoogleServiceAccount $account, string $uri, array $query): ?array
    {
        $token = $this->token($account);

        if (null === $token) {
            return null;
        }

        try {
            return $this->httpClient->request('GET', $uri, [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'query' => $query,
                'timeout' => self::TIMEOUT_SECONDS,
            ])->toArray();
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive request failed.', [
                'uri' => $uri,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The assertion exchanged for an access token, kept for as long as it is
     * valid.
     *
     * The cache key carries the account's address: two installations, or a
     * replaced account, must not share a token.
     */
    private function token(GoogleServiceAccount $account): ?string
    {
        try {
            $token = $this->cache->get(
                'studio.drive.token.'.md5($account->email),
                function (ItemInterface $item, bool &$save) use ($account): ?string {
                    $item->expiresAfter(self::TOKEN_TTL_SECONDS);

                    $payload = $this->httpClient->request('POST', GoogleServiceAccount::TOKEN_URI, [
                        'body' => [
                            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                            'assertion' => $account->assertion(self::SCOPE),
                        ],
                        'timeout' => self::TIMEOUT_SECONDS,
                    ])->toArray();

                    $access = $payload['access_token'] ?? null;

                    if (!is_string($access) || '' === $access) {
                        // **Not stored at all**, rather than stored for one
                        // second: the cache contract keeps `null` values too,
                        // so a refusal would silence the integration for fifty
                        // minutes. A key that was just fixed must work on the
                        // next reload.
                        $save = false;

                        return null;
                    }

                    return $access;
                },
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive token exchange failed.', ['exception' => $throwable->getMessage()]);

            return null;
        }

        return $token;
    }
}
