<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\Service;

use Aurora\Core\Locale\Repository\LocaleRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Caches the feed per locale, dropped on the same events as the sitemap —
 * publishing a post should reach both at once, and a reader polling every
 * few minutes should not cost a query each time.
 */
final readonly class RssFeedService
{
    private const string CACHE_KEY_PREFIX = 'editorial.rss.feed.';

    private const int TTL_SECONDS = 3600;

    public function __construct(
        private RssFeedBuilder $builder,
        private LocaleRepository $localeRepository,
        private CacheInterface $cache,
    ) {}

    public function getXml(string $locale): string
    {
        return $this->cache->get(
            self::CACHE_KEY_PREFIX.$locale,
            function (ItemInterface $item) use ($locale): string {
                $item->expiresAfter(self::TTL_SECONDS);

                return $this->builder->buildXml($locale);
            },
        );
    }

    /**
     * Drops every locale, not only the active ones. A locale switched off
     * keeps whatever was cached for it, and switching it back on months
     * later would serve that stale feed until the TTL noticed.
     */
    public function invalidate(): void
    {
        foreach ($this->localeRepository->findAll() as $locale) {
            $this->cache->delete(self::CACHE_KEY_PREFIX.$locale->getCode());
        }
    }
}
