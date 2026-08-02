<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Support;

use Aurora\Core\Support\TreeReorderParser;
use PHPUnit\Framework\TestCase;

final class TreeReorderParserTest extends TestCase
{
    public function testNormalisesAWellFormedPayload(): void
    {
        self::assertSame(
            [
                ['id' => 3, 'parentId' => null, 'position' => 0],
                ['id' => 4, 'parentId' => 3, 'position' => 1],
            ],
            TreeReorderParser::parse([
                ['id' => '3', 'parentId' => 0, 'position' => '0'],
                ['id' => 4, 'parentId' => '3', 'position' => 1],
            ]),
        );
    }

    /** A stale row in the browser should not cost the rest of the reorder. */
    public function testDropsEntriesWithNoUsableIdRatherThanRejectingTheBatch(): void
    {
        self::assertSame(
            [['id' => 5, 'parentId' => null, 'position' => 0]],
            TreeReorderParser::parse([
                'rubbish',
                ['parentId' => 1],
                ['id' => 0, 'position' => 3],
                ['id' => 5, 'position' => 0],
            ]),
        );
    }

    public function testTreatsAnythingButAListAsEmpty(): void
    {
        self::assertSame([], TreeReorderParser::parse(null));
        self::assertSame([], TreeReorderParser::parse('nope'));
        self::assertSame([], TreeReorderParser::parse([]));
    }
}
