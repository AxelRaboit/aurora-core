<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning;

use Aurora\Module\Planning\Feed\IcalWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The two parts of iCalendar that are actually easy to get wrong.
 *
 * Escaping and folding, tested directly rather than through a whole document,
 * because a feed with one accented title in it either parses or shows nothing at
 * all - and "shows nothing" is indistinguishable from "no events" to whoever
 * subscribed.
 */
final class IcalWriterTest extends TestCase
{
    private function call(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(IcalWriter::class, $method);

        return $reflection->invoke(new IcalWriter(), ...$arguments);
    }

    public function testItEscapesTheFourReservedCharacters(): void
    {
        self::assertSame('a\\,b', $this->call('escape', 'a,b'));
        self::assertSame('a\;b', $this->call('escape', 'a;b'));
        self::assertSame('a\\nb', $this->call('escape', "a\nb"));
        self::assertSame('a\\\\b', $this->call('escape', 'a\\b'));
    }

    /**
     * The backslash goes first, or the escapes added after it get escaped again.
     */
    public function testItDoesNotDoubleEscapeItsOwnEscapes(): void
    {
        // Built rather than written as a literal, because the answer is three
        // backslashes and a comma - and a PHP literal for that is unreadable
        // enough that the first version of this test asserted the wrong string.
        $backslash = '\\';
        $expected = 'a'.$backslash.$backslash.$backslash.',b';

        self::assertSame($expected, $this->call('escape', 'a'.$backslash.',b'));
    }

    public function testACarriageReturnBecomesOneNewline(): void
    {
        // A CRLF is one line break, and writing it as two would put an empty line
        // into every description typed on Windows.
        self::assertSame('a\\nb', $this->call('escape', "a\r\nb"));
    }

    public function testAShortLineIsLeftAlone(): void
    {
        self::assertSame(['SUMMARY:court'], $this->call('fold', 'SUMMARY:court'));
    }

    public function testALongLineIsFoldedWithLeadingSpaces(): void
    {
        $folded = $this->call('fold', 'SUMMARY:'.str_repeat('a', 200));

        self::assertGreaterThan(1, count($folded));
        self::assertSame(75, mb_strlen($folded[0]));

        foreach (array_slice($folded, 1) as $continuation) {
            self::assertStringStartsWith(' ', $continuation);
            self::assertLessThanOrEqual(75, mb_strlen($continuation));
        }
    }

    /**
     * Folded on octets, and never through the middle of a character.
     *
     * The standard counts octets, so a naive split at 75 characters overflows -
     * and a split at 75 octets can cut a multi-byte character in half, which is
     * how a feed with one accented title fails to parse.
     */
    public function testItNeverCutsAMultiByteCharacterInHalf(): void
    {
        $folded = $this->call('fold', 'SUMMARY:'.str_repeat('é', 100));

        foreach ($folded as $line) {
            self::assertLessThanOrEqual(75, mb_strlen($line));
            // Still valid UTF-8, which it would not be through a split character.
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'A line was cut mid-character.');
        }

        // And nothing was lost on the way.
        self::assertSame(
            'SUMMARY:'.str_repeat('é', 100),
            implode('', array_map(static fn (string $line, int $i): string => 0 === $i ? $line : mb_substr($line, 1), $folded, array_keys($folded))),
        );
    }
}
