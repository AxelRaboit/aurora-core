<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Search;

use Aurora\Core\Search\RelevanceSorter;
use PHPUnit\Framework\TestCase;

final class RelevanceSorterTest extends TestCase
{
    /** @return object{id: int} */
    private function makeItem(int $id): object
    {
        return new class($id) {
            public function __construct(public readonly int $id) {}
        };
    }

    public function testSortReordersAccordingToIdList(): void
    {
        $sorter = new RelevanceSorter();

        $item1 = $this->makeItem(1);
        $item2 = $this->makeItem(2);
        $item3 = $this->makeItem(3);

        // items hydrated in arbitrary order, ordered ids prioritize 3, 1, 2
        $sorted = $sorter->sort([$item1, $item2, $item3], [3, 1, 2], static fn (object $item): int => $item->id);

        self::assertSame($item3, $sorted[0]);
        self::assertSame($item1, $sorted[1]);
        self::assertSame($item2, $sorted[2]);
    }

    public function testSortPushesUnknownIdsToEnd(): void
    {
        $sorter = new RelevanceSorter();

        $item1 = $this->makeItem(1);
        $item99 = $this->makeItem(99);
        $item2 = $this->makeItem(2);

        // item99 is not in orderedIds - should go last
        $sorted = $sorter->sort([$item1, $item99, $item2], [2, 1], static fn (object $item): int => $item->id);

        self::assertSame($item2, $sorted[0]);
        self::assertSame($item1, $sorted[1]);
        self::assertSame($item99, $sorted[2]);
    }

    public function testSortWithEmptyInput(): void
    {
        self::assertSame([], (new RelevanceSorter())->sort([], [1, 2, 3], static fn (object $item): int => $item->id));
    }

    public function testSortWithEmptyOrderingPreservesCount(): void
    {
        $sorter = new RelevanceSorter();
        $sorted = $sorter->sort([$this->makeItem(1), $this->makeItem(2)], [], static fn (object $item): int => $item->id);

        self::assertCount(2, $sorted);
    }
}
