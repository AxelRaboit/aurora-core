<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How many things wait behind a menu entry, for the figure the side menu
 * prints beside its name.
 *
 * A module declares which of its entries it counts, by their stable key
 * ({@see NavItem::stableKey()}), and answers for one key at a time. The menu
 * only asks about the entries it is actually drawing, so a count behind a
 * privilege the reader lacks, or behind an entry they hid, is never run: an
 * entry that is not shown does not cost a query.
 *
 * **A count answers for the reader, like the list it leads to.** An entry
 * whose list is scoped (a contributor sees only their own publications) must
 * count with the same scope - a figure larger than the list it opens would be
 * a count of things the reader was never shown. Leave out an entry whose list
 * cannot be counted cheaply with its own rules rather than approximating it.
 *
 * Called on every page of the suite: one cheap query per entry, nothing that
 * loads rows.
 */
#[AutoconfigureTag(self::TAG)]
interface NavItemCountProviderInterface
{
    public const string TAG = 'aurora.nav_item_count_provider';

    /**
     * The stable keys of the entries this provider counts.
     *
     * @return list<string>
     */
    public function getCountedItemKeys(): array;

    /** The figure for one of the keys returned above. */
    public function countItem(string $itemKey): int;
}
