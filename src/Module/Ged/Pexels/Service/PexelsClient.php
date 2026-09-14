<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Pexels\Service;

use Aurora\Module\Ged\Pexels\Setting\PexelsSettings;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Talks to Pexels on the server's behalf.
 *
 * The API key never reaches the browser. It could have: the search endpoint
 * is public and a Vue component could call it directly, which is how most
 * tutorials do it. But a key in a bundle is a key anyone can read and spend,
 * and the hourly quota is the installation's, not the visitor's - so the
 * admin asks Aurora, and Aurora asks Pexels.
 *
 * Failures are swallowed into an empty result rather than raised. A stock
 * photo search that cannot reach its provider is a picker with nothing in
 * it, which is a disappointment; an exception here would be a 500 in the
 * middle of editing a page, which is a lost afternoon.
 */
final readonly class PexelsClient
{
    private const string API_BASE = 'https://api.pexels.com/v1';

    private const int TIMEOUT_SECONDS = 5;

    private const int PER_PAGE = 24;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private PexelsSettings $settings,
    ) {}

    /**
     * The key lives in the database rather than in the environment, because
     * the account it belongs to is the client's. Asking them to hand it over
     * so somebody else can paste it into a server file is the wrong shape:
     * they enter it in their own back office, and nobody else needs to see
     * it. {@see PexelsSettings} also answers whether they turned the
     * integration on and accepted the terms, which is the same question.
     */
    public function isConfigured(): bool
    {
        return $this->settings->isEnabled();
    }

    /**
     * @return array{results: list<array<string, mixed>>, totalPages: int}
     */
    public function search(string $query, int $page = 1): array
    {
        $empty = ['results' => [], 'totalPages' => 0];

        if (!$this->isConfigured() || '' === mb_trim($query)) {
            return $empty;
        }

        try {
            $response = $this->httpClient->request('GET', self::API_BASE.'/search', [
                // Pexels wants the bare key, with no scheme in front of it.
                'headers' => ['Authorization' => $this->settings->apiKey()],
                'query' => [
                    'query' => $query,
                    'page' => max(1, $page),
                    'per_page' => self::PER_PAGE,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $payload = $response->toArray();
        } catch (Throwable $throwable) {
            $this->logger->warning('Pexels search failed.', [
                'query' => $query,
                'exception' => $throwable->getMessage(),
            ]);

            return $empty;
        }

        /* @var array<string, mixed> $payload */
        return [
            'results' => array_map($this->normalizePhoto(...), (array) ($payload['photos'] ?? [])),
            'totalPages' => $this->totalPages($payload),
        ];
    }

    /**
     * One photo, named rather than searched for.
     *
     * The picker never needs this: it already holds everything about a photo
     * by the time somebody clicks it, because the search handed it over. A
     * caller that has only an id does not - a console import, a fixture, a
     * script reproducing a page - and asking it to search for a word that
     * happens to return the right photo again is not an answer.
     *
     * Null covers both "no such photo" and "could not ask", deliberately: the
     * distinction needs the provider to be reachable to be made at all, and
     * the caller's next move is the same either way. What tells them apart is
     * the log line.
     *
     * @return array<string, mixed>|null
     */
    public function photo(string $id): ?array
    {
        $id = mb_trim($id);

        if (!$this->isConfigured() || '' === $id) {
            return null;
        }

        try {
            $payload = $this->httpClient->request('GET', self::API_BASE.'/photos/'.rawurlencode($id), [
                'headers' => ['Authorization' => $this->settings->apiKey()],
                'timeout' => self::TIMEOUT_SECONDS,
            ])->toArray();
        } catch (Throwable $throwable) {
            $this->logger->warning('Pexels photo lookup failed.', [
                'id' => $id,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }

        /* @var array<string, mixed> $payload */
        $photo = $this->normalizePhoto($payload);

        // An answer shaped like a photo but carrying no file is not one. The
        // importer would refuse it a moment later; refusing it here means the
        // caller is told which id was hollow rather than which download failed.
        return '' === $photo['url'] ? null : $photo;
    }

    /**
     * Pexels reports a number of results and leaves the division to us,
     * where Unsplash reported pages directly.
     *
     * `per_page` is read from the answer rather than assumed: it is the
     * provider that decides how many it actually returned, and paging built
     * on our own guess would run past the end.
     *
     * @param array<string, mixed> $payload
     */
    private function totalPages(array $payload): int
    {
        $perPage = max(1, (int) ($payload['per_page'] ?? self::PER_PAGE));

        return (int) ceil((int) ($payload['total_results'] ?? 0) / $perPage);
    }

    /**
     * Keeps only what the picker and the import need.
     *
     * A raw Pexels photo carries eight renditions and a handful of fields we
     * have no use for; passing it through would put their whole schema in our
     * Vue component and in our database.
     *
     * `original` is what gets stored, untouched: their named renditions are
     * cropped to a fixed box - `large` is 940x650 whatever the photo was - and
     * a picture that arrives pre-cropped can never be shown whole again.
     * DocumentUrlGenerator asks the CDN for the widths we want instead.
     *
     * @param array<string, mixed> $photo
     *
     * @return array<string, mixed>
     */
    private function normalizePhoto(array $photo): array
    {
        $src = (array) ($photo['src'] ?? []);

        return [
            'id' => (string) ($photo['id'] ?? ''),
            'url' => (string) ($src['original'] ?? ''),
            // Their smallest rendition, and only ever drawn in the picker's grid.
            'thumbUrl' => (string) ($src['tiny'] ?? ''),
            'width' => (int) ($photo['width'] ?? 0),
            'height' => (int) ($photo['height'] ?? 0),
            // Pexels writes an alt for most photos; it becomes our title and
            // our alt text, so an editor is not left naming files by hand.
            'description' => $photo['alt'] ?? null,
            'color' => $photo['avg_color'] ?? null,
            'authorName' => (string) ($photo['photographer'] ?? ''),
            'authorUrl' => (string) ($photo['photographer_url'] ?? ''),
        ];
    }
}
