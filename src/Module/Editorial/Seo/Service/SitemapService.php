<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\Service;

use Aurora\Module\Editorial\Seo\Dto\SitemapData;
use DateTimeImmutable;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Caches the built sitemap.
 *
 * `/sitemap.xml` is the one public route whose cost scales with the whole
 * site rather than with one page, and crawlers ask for it repeatedly. The
 * cache is dropped whenever content changes, so the hour below is only a
 * backstop for a site nobody is editing.
 */
final readonly class SitemapService
{
    private const string CACHE_KEY = 'editorial.sitemap.data';

    private const int TTL_SECONDS = 3600;

    public function __construct(
        private SitemapBuilder $builder,
        private CacheInterface $cache,
    ) {}

    public function getData(): SitemapData
    {
        $payload = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->serialize($this->builder->buildData());
        });

        return $this->hydrate($payload);
    }

    public function invalidate(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * Stored as a plain array rather than the object: a deployment that adds
     * a field to SitemapData would otherwise fail to unserialize whatever the
     * previous version left in the cache. Missing keys fall back below.
     *
     * @return array<string, mixed>
     */
    private function serialize(SitemapData $data): array
    {
        return [
            'xml' => $data->xml,
            'counts' => $data->counts,
            'byPostType' => $data->byPostType,
            'byLocale' => $data->byLocale,
            'noindex' => $data->noindex,
            'generatedAt' => $data->generatedAt->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function hydrate(array $payload): SitemapData
    {
        /** @var array{home: int, archives: int, posts: int, terms: int} $counts */
        $counts = $payload['counts'] ?? ['home' => 0, 'archives' => 0, 'posts' => 0, 'terms' => 0];

        return new SitemapData(
            xml: (string) ($payload['xml'] ?? ''),
            counts: $counts,
            byPostType: $payload['byPostType'] ?? [],
            byLocale: $payload['byLocale'] ?? [],
            noindex: (int) ($payload['noindex'] ?? 0),
            generatedAt: new DateTimeImmutable((string) ($payload['generatedAt'] ?? 'now')),
        );
    }
}
