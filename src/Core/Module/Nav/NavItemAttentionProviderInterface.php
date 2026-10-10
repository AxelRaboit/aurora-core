<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How many things behind a menu entry are waiting on somebody, for the
 * coloured pill the side menu draws beside its name.
 *
 * **Not a second count.** The grey figure ({@see NavItemCountProviderInterface})
 * says how many there are, and is always there; this says how many need doing
 * today, and is drawn only when there is something to do. The two can sit on
 * the same entry - 42 customers, 3 follow-ups due - and must not look alike,
 * or "3" beside "Customers" reads as three customers.
 *
 * Same contract otherwise: one cheap query per entry, answered for the reader,
 * and only for the entries the menu is drawing.
 */
#[AutoconfigureTag(self::TAG)]
interface NavItemAttentionProviderInterface
{
    public const string TAG = 'aurora.nav_item_attention_provider';

    /**
     * The stable keys of the entries this provider flags.
     *
     * @return list<string>
     */
    public function getAttentionItemKeys(): array;

    /** How many need attention behind this entry; zero draws nothing. */
    public function countAttention(string $itemKey): int;

    /**
     * What the pill says to whoever cannot see its colour, as a translation
     * key taking `count` ("{count} follow-up due | {count} follow-ups due").
     */
    public function getAttentionLabelKey(string $itemKey): string;
}
