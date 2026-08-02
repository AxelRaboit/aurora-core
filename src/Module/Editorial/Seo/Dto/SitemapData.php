<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\Dto;

use DateTimeImmutable;

/**
 * A built sitemap and what went into it.
 *
 * The counts travel with the XML rather than being recomputed, so the backend
 * screen and the public `/sitemap.xml` share one pass over the database — and
 * so the figures an editor reads describe the file a crawler would actually
 * receive, not a second walk that might disagree with it.
 *
 * @phpstan-type Counts array{home: int, archives: int, posts: int, terms: int}
 */
final readonly class SitemapData
{
    /**
     * @param Counts             $counts     URL count per top-level section
     * @param array<string, int> $byPostType URL count per post type slug (posts only)
     * @param array<string, int> $byLocale   URL count per locale code (all sections)
     * @param int                $noindex    post translations left out because they ask not to be indexed
     */
    public function __construct(
        public string $xml,
        public array $counts,
        public array $byPostType,
        public array $byLocale,
        public int $noindex,
        public DateTimeImmutable $generatedAt,
    ) {}

    public function totalUrls(): int
    {
        return array_sum($this->counts);
    }

    public function sizeBytes(): int
    {
        return mb_strlen($this->xml, '8bit');
    }
}
