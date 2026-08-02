<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Service;

use Aurora\Module\Editorial\Taxonomy\Service\TaxonomyReorderParser;
use PHPUnit\Framework\TestCase;

final class TaxonomyReorderParserTest extends TestCase
{
    public function testNormalisesAWellFormedPayload(): void
    {
        self::assertSame(
            [
                ['id' => 3, 'parentId' => null, 'position' => 0],
                ['id' => 4, 'parentId' => 3, 'position' => 1],
            ],
            TaxonomyReorderParser::parse([
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
            TaxonomyReorderParser::parse([
                'rubbish',
                ['parentId' => 1],
                ['id' => 0, 'position' => 3],
                ['id' => 5, 'position' => 0],
            ]),
        );
    }

    public function testTreatsAnythingButAListAsEmpty(): void
    {
        self::assertSame([], TaxonomyReorderParser::parse(null));
        self::assertSame([], TaxonomyReorderParser::parse('nope'));
        self::assertSame([], TaxonomyReorderParser::parse([]));
    }
}
