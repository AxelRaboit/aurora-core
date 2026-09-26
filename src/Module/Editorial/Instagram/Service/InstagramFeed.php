<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Instagram\Service;

use Aurora\Module\Editorial\GitHub\Service\GitHubContributions;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function array_slice;
use function is_array;
use function is_string;
use function md5;
use function rawurlencode;
use function sprintf;

/**
 * The client's own recent Instagram posts, read from the Graph API with
 * their own long-lived token.
 *
 * Cached the way the GitHub contributions grid is: six hours, a retry after
 * ten minutes on failure, and the last feed read kept thirty days so a token
 * that stops working for a day does not empty the page - {@see GitHubContributions}
 * is the same shape for the same reason.
 */
final readonly class InstagramFeed
{
    private const string API = 'https://graph.facebook.com/v19.0/%s/media';

    private const string CACHE_KEY = 'editorial.instagram.feed.';

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
     * @return list<array{id: string, url: string, thumbnail: string, permalink: string, caption: string, isVideo: bool}>|null
     */
    public function forAccount(string $businessAccountId, string $accessToken, int $limit): ?array
    {
        $key = self::CACHE_KEY.md5($businessAccountId);

        $feed = $this->cache->get($key, function (ItemInterface $item) use ($businessAccountId, $accessToken, $key): ?array {
            $fresh = $this->fetch($businessAccountId, $accessToken);

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

        return null === $feed ? null : array_slice($feed, 0, $limit);
    }

    /**
     * @return list<array{id: string, url: string, thumbnail: string, permalink: string, caption: string, isVideo: bool}>|null
     */
    private function fetch(string $businessAccountId, string $accessToken): ?array
    {
        try {
            $response = $this->httpClient->request('GET', sprintf(self::API, rawurlencode($businessAccountId)), [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'query' => [
                    'fields' => 'id,media_type,media_url,thumbnail_url,permalink,caption',
                    'access_token' => $accessToken,
                    'limit' => 24,
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('Instagram Graph API answered {status}.', ['status' => $response->getStatusCode()]);

                return null;
            }

            $data = $response->toArray(false);
        } catch (Throwable $throwable) {
            $this->logger->warning('Instagram Graph API could not be reached.', ['exception' => $throwable]);

            return null;
        }

        $items = is_array($data['data'] ?? null) ? $data['data'] : [];
        $feed = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!is_string($item['id'] ?? null)) {
                continue;
            }

            // A video's own frame is not in `media_url`, which plays the film
            // itself; the feed shows a still, so it reads `thumbnail_url` first
            // and falls back to the image for a photo, which has no other.
            $isVideo = 'VIDEO' === ($item['media_type'] ?? null);
            $thumbnail = is_string($item['thumbnail_url'] ?? null) ? $item['thumbnail_url'] : (is_string($item['media_url'] ?? null) ? $item['media_url'] : '');

            if ('' === $thumbnail) {
                continue;
            }

            $feed[] = [
                'id' => $item['id'],
                'url' => is_string($item['media_url'] ?? null) ? $item['media_url'] : $thumbnail,
                'thumbnail' => $thumbnail,
                'permalink' => is_string($item['permalink'] ?? null) ? $item['permalink'] : '',
                'caption' => is_string($item['caption'] ?? null) ? $item['caption'] : '',
                'isVideo' => $isVideo,
            ];
        }

        return [] === $feed ? null : $feed;
    }
}
