<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GoogleReviews\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function md5;
use function rawurlencode;
use function sprintf;

/**
 * A business's rating and its most recent reviews, from the Places API.
 *
 * Cached six hours with a thirty-day stale fallback, the same shape as the
 * GitHub contributions grid: a business's rating does not move fast enough
 * to justify asking Google on every visit, and a quota exhausted for a day
 * should not empty the page.
 */
final readonly class GoogleReviews
{
    private const string API = 'https://maps.googleapis.com/maps/api/place/details/json';

    private const string CACHE_KEY = 'editorial.google_reviews.';

    private const int TTL_SECONDS = 21_600;

    private const int RETRY_SECONDS = 600;

    private const int STALE_SECONDS = 2_592_000;

    private const int TIMEOUT_SECONDS = 6;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{rating: float, total: int, url: string, reviews: list<array{author: string, photo: string, rating: int, relativeTime: string, text: string}>}|null
     */
    public function forPlace(string $placeId, string $apiKey, string $locale): ?array
    {
        $key = self::CACHE_KEY.md5($placeId.'.'.$locale);

        return $this->cache->get($key, function (ItemInterface $item) use ($placeId, $apiKey, $locale, $key): ?array {
            $fresh = $this->fetch($placeId, $apiKey, $locale);

            if (null !== $fresh) {
                $item->expiresAfter(self::TTL_SECONDS);
                $this->cache->delete($key.'.stale');
                $this->cache->get($key.'.stale', static function (ItemInterface $stale) use ($fresh): array {
                    $stale->expiresAfter(self::STALE_SECONDS);

                    return $fresh;
                });

                return $fresh;
            }

            $item->expiresAfter(self::RETRY_SECONDS);

            return $this->cache->get($key.'.stale', static function (ItemInterface $stale, bool &$save): ?array {
                $save = false;

                return null;
            });
        });
    }

    /**
     * @return array{rating: float, total: int, url: string, reviews: list<array{author: string, photo: string, rating: int, relativeTime: string, text: string}>}|null
     */
    private function fetch(string $placeId, string $apiKey, string $locale): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::API, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'query' => [
                    'place_id' => $placeId,
                    'fields' => 'rating,user_ratings_total,url,reviews',
                    'language' => $locale,
                    'key' => $apiKey,
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $data = $response->toArray(false);

            if ('OK' !== ($data['status'] ?? null)) {
                $this->logger->warning('Google Places API answered {status}.', ['status' => $data['status'] ?? 'unknown']);

                return null;
            }
        } catch (Throwable $throwable) {
            $this->logger->warning('Google Places API could not be reached.', ['exception' => $throwable]);

            return null;
        }

        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        $reviews = [];

        foreach (is_array($result['reviews'] ?? null) ? $result['reviews'] : [] as $review) {
            if (!is_array($review)) {
                continue;
            }

            if (!is_string($review['author_name'] ?? null)) {
                continue;
            }

            $reviews[] = [
                'author' => $review['author_name'],
                'photo' => is_string($review['profile_photo_url'] ?? null) ? $review['profile_photo_url'] : '',
                'rating' => is_int($review['rating'] ?? null) ? $review['rating'] : 0,
                'relativeTime' => is_string($review['relative_time_description'] ?? null) ? $review['relative_time_description'] : '',
                'text' => is_string($review['text'] ?? null) ? $review['text'] : '',
            ];
        }

        $rating = $result['rating'] ?? null;

        if (!is_float($rating) && !is_int($rating)) {
            return null;
        }

        return [
            'rating' => (float) $rating,
            'total' => is_int($result['user_ratings_total'] ?? null) ? $result['user_ratings_total'] : 0,
            'url' => is_string($result['url'] ?? null) ? $result['url'] : sprintf('https://search.google.com/local/writereview?placeid=%s', rawurlencode($placeId)),
            'reviews' => $reviews,
        ];
    }
}
