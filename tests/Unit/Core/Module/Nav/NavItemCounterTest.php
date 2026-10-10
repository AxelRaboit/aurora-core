<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Module\Nav;

use Aurora\Core\Module\Nav\NavItemCounter;
use Aurora\Core\Module\Nav\NavItemCountProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * The menu's figures are written into the resolved menu, entry by entry.
 *
 * What matters is less the arithmetic than the shape: an entry nobody counts
 * keeps no `count` at all (the menu then draws nothing, rather than a
 * misleading zero), children are counted like their parents, and a provider is
 * only asked about entries the menu is drawing.
 */
final class NavItemCounterTest extends TestCase
{
    public function testACountedEntryCarriesItsFigure(): void
    {
        $counter = new NavItemCounter([$this->provider(['posts' => 17])]);

        $sections = $counter->annotateContainers([
            ['id' => 'editorial', 'items' => [['key' => 'posts', 'children' => []]]],
        ]);

        self::assertSame(17, $sections[0]['items'][0]['count']);
        self::assertSame('editorial', $sections[0]['id']);
    }

    public function testAnEntryNobodyCountsHasNoCountAtAll(): void
    {
        $counter = new NavItemCounter([$this->provider(['posts' => 17])]);

        $items = $counter->annotateItems([['key' => 'menus', 'children' => []]]);

        self::assertArrayNotHasKey('count', $items[0]);
    }

    public function testAZeroIsStillAFigure(): void
    {
        $counter = new NavItemCounter([$this->provider(['trash' => 0])]);

        $items = $counter->annotateItems([['key' => 'trash', 'children' => []]]);

        self::assertSame(0, $items[0]['count']);
    }

    public function testChildrenAreCountedLikeTheirParent(): void
    {
        $counter = new NavItemCounter([$this->provider(['child' => 3])]);

        $items = $counter->annotateItems([
            ['key' => 'parent', 'children' => [['key' => 'child', 'children' => []]]],
        ]);

        self::assertArrayNotHasKey('count', $items[0]);
        self::assertSame(3, $items[0]['children'][0]['count']);
    }

    public function testOnlyTheEntriesDrawnAreAsked(): void
    {
        $provider = $this->provider(['posts' => 1, 'hidden' => 2]);
        $counter = new NavItemCounter([$provider]);

        $counter->annotateItems([['key' => 'posts', 'children' => []]]);

        self::assertSame(['posts'], $provider->asked);
    }

    /**
     * @param array<string, int> $figures
     */
    private function provider(array $figures): object
    {
        return new class($figures) implements NavItemCountProviderInterface {
            /** @var list<string> */
            public array $asked = [];

            /**
             * @param array<string, int> $figures
             */
            public function __construct(private readonly array $figures) {}

            public function getCountedItemKeys(): array
            {
                return array_keys($this->figures);
            }

            public function countItem(string $itemKey): int
            {
                $this->asked[] = $itemKey;

                return $this->figures[$itemKey];
            }
        };
    }
}
