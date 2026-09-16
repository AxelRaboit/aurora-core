<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage;

use Aurora\Core\Storage\StoredFileName;
use PHPUnit\Framework\TestCase;

use function mb_strlen;
use function preg_match;

/**
 * The name a file is stored under is the only lock on most of what Aurora
 * serves, so it has to be a name nobody works out.
 */
final class StoredFileNameTest extends TestCase
{
    public function testItIsHexAndFullLength(): void
    {
        $name = StoredFileName::bare();

        // Two hex characters per byte, and nothing else in it.
        self::assertSame(StoredFileName::ENTROPY_BYTES * 2, mb_strlen($name));
        self::assertSame(1, preg_match('/^[0-9a-f]+$/', $name));
    }

    /**
     * The regression this file exists for.
     *
     * Names used to be `slug(original name) + uniqid()`: the first half often
     * guessable, the second the microsecond of the upload. Two names produced
     * in the same tick were a few characters apart, which is what made a
     * published document's address enumerable.
     */
    public function testTwoNamesMadeInTheSameTickShareNothing(): void
    {
        $names = [];

        for ($i = 0; $i < 200; ++$i) {
            $names[] = StoredFileName::bare();
        }

        self::assertCount(200, array_unique($names));

        // Not merely different: different from the first character. A
        // time-derived name shares its whole leading run with its neighbour.
        $firstCharacters = array_unique(array_map(
            static fn (string $name): string => $name[0],
            $names,
        ));
        self::assertGreaterThan(8, count($firstCharacters));
    }

    public function testTheExtensionIsCarriedAndNothingElseIs(): void
    {
        $name = StoredFileName::withExtension('jpg');

        self::assertSame(1, preg_match('/^[0-9a-f]{32}\.jpg$/', $name));
    }
}
