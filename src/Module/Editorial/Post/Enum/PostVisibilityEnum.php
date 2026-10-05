<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Enum;

/**
 * Where a published publication can be read.
 *
 * Separate from the status on purpose. The status says whether a visitor may
 * read the publication at all; this says whether the site offers it. A
 * publication shared by link is published - its readers are visitors like any
 * other - but nothing on the site leads to it: no address under the site, no
 * listing, no menu, no sitemap, no search.
 *
 * The use it was made for is a client deliverable, an audit or a strategy,
 * written with the same grid as the site's pages and read by the one client
 * it was written for.
 */
enum PostVisibilityEnum: string
{
    /** On the site: its address, the listings, the menus, the sitemap. */
    case Site = 'site';

    /** Read through a link only. The site never names it. */
    case Link = 'link';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabelKey(): string
    {
        return 'suite.posts.visibility.'.$this->value;
    }
}
