<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function array_slice;
use function is_array;
use function is_int;
use function is_string;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;
use function preg_replace;
use function preg_split;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * A repository's card and its latest releases, from GitHub's public API.
 *
 * Unauthenticated, so GitHub allows sixty calls an hour from one server. A
 * page shows at most six repositories and is read from the cache for six
 * hours, so a site stays far below that whatever its traffic; a refusal or an
 * outage serves the last answer for a month, as the contributions grid does.
 */
final readonly class GitHubRepositories
{
    private const string API = 'https://api.github.com/repos/%s';

    private const string CACHE_KEY = 'editorial.github.repos.';

    private const int TTL_SECONDS = 21_600;

    private const int RETRY_SECONDS = 600;

    private const int STALE_SECONDS = 2_592_000;

    private const int TIMEOUT_SECONDS = 5;

    public const int RELEASES_PER_REPO = 3;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{name: string, fullName: string, url: string, description: string, language: string, stars: int, forks: int, pushedAt: string}|null
     */
    public function repository(string $repo): ?array
    {
        return $this->cached('repo.'.$repo, function () use ($repo): ?array {
            $data = $this->get(sprintf(self::API, $repo));

            if (!is_array($data) || !is_string($data['full_name'] ?? null)) {
                return null;
            }

            return [
                'name' => (string) ($data['name'] ?? ''),
                'fullName' => $data['full_name'],
                'url' => is_string($data['html_url'] ?? null) ? $data['html_url'] : sprintf('https://github.com/%s', $repo),
                'description' => is_string($data['description'] ?? null) ? $data['description'] : '',
                'language' => is_string($data['language'] ?? null) ? $data['language'] : '',
                'stars' => is_int($data['stargazers_count'] ?? null) ? $data['stargazers_count'] : 0,
                'forks' => is_int($data['forks_count'] ?? null) ? $data['forks_count'] : 0,
                'pushedAt' => is_string($data['pushed_at'] ?? null) ? $data['pushed_at'] : '',
            ];
        });
    }

    /**
     * @return list<array{tag: string, name: string, url: string, publishedAt: string, summary: string}>|null
     */
    public function releases(string $repo): ?array
    {
        return $this->cached('releases.'.$repo, function () use ($repo): ?array {
            $data = $this->get(sprintf(self::API.'/releases?per_page=%d', $repo, self::RELEASES_PER_REPO));

            if (!is_array($data)) {
                return null;
            }

            $releases = [];
            foreach (array_slice($data, 0, self::RELEASES_PER_REPO) as $release) {
                if (!is_array($release)) {
                    continue;
                }

                if (!is_string($release['tag_name'] ?? null)) {
                    continue;
                }

                if (true === ($release['draft'] ?? false)) {
                    continue;
                }

                $releases[] = [
                    'tag' => $release['tag_name'],
                    'name' => is_string($release['name'] ?? null) && '' !== $release['name'] ? $release['name'] : $release['tag_name'],
                    'url' => is_string($release['html_url'] ?? null) ? $release['html_url'] : '',
                    'publishedAt' => is_string($release['published_at'] ?? null) ? $release['published_at'] : '',
                    'summary' => self::summary(is_string($release['body'] ?? null) ? $release['body'] : ''),
                ];
            }

            return $releases;
        });
    }

    /**
     * The first sentence-sized line of release notes, without its Markdown.
     */
    public static function summary(string $body): string
    {
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $plain = mb_trim((string) preg_replace(['/^#+\s*|^[-*>]\s+/', '/[*_`]|\[([^\]]*)\]\([^)]*\)/'], ['', '$1'], mb_trim($line)));

            if ('' !== $plain && !str_starts_with($plain, '---')) {
                return mb_substr(str_replace("\u{a0}", ' ', $plain), 0, 180);
            }
        }

        return '';
    }

    /**
     * @template T
     *
     * @param callable(): (T|null) $fetch
     *
     * @return T|null
     */
    private function cached(string $name, callable $fetch): mixed
    {
        $key = self::CACHE_KEY.str_replace(['/', '{', '}', '(', ')', '\\', '@', ':'], '_', mb_strtolower($name));

        return $this->cache->get($key, function (ItemInterface $item) use ($fetch, $key): mixed {
            $fresh = $fetch();

            if (null !== $fresh) {
                $item->expiresAfter(self::TTL_SECONDS);
                $this->cache->delete($key.'.stale');
                $this->cache->get($key.'.stale', static function (ItemInterface $stale) use ($fresh): mixed {
                    $stale->expiresAfter(self::STALE_SECONDS);

                    return $fresh;
                });

                return $fresh;
            }

            $item->expiresAfter(self::RETRY_SECONDS);

            return $this->cache->get($key.'.stale', static function (ItemInterface $stale, bool &$save): mixed {
                $save = false;

                return null;
            });
        });
    }

    private function get(string $url): mixed
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'application/vnd.github+json'],
            ]);

            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('GitHub API answered {status}.', ['status' => $response->getStatusCode(), 'url' => $url]);

                return null;
            }

            return $response->toArray();
        } catch (Throwable $throwable) {
            $this->logger->warning('GitHub API could not be reached.', ['url' => $url, 'exception' => $throwable]);

            return null;
        }
    }
}
