<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning;

use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Time\PlanningClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The module's one rule about time, now that it is written once.
 *
 * It was written eight times before, and had already been got wrong twice - both
 * times by a copy that was not updated, and both times invisibly until an event
 * drew itself in the wrong place. This is the test that makes the next such
 * mistake loud.
 */
final class PlanningClockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function instants(): iterable
    {
        yield 'already UTC' => ['2026-09-01T08:00:00Z', '2026-09-01 08:00'];
        yield 'an offset is honoured, not stripped' => ['2026-09-01T10:00:00+02:00', '2026-09-01 08:00'];
        yield 'a negative offset too' => ['2026-09-01T04:00:00-04:00', '2026-09-01 08:00'];
        yield 'a date alone is midnight UTC' => ['2026-09-01', '2026-09-01 00:00'];
    }

    #[DataProvider('instants')]
    public function testAnInstantArrivesInUtcHoweverItWasWritten(string $sent, string $expected): void
    {
        $parsed = PlanningClock::utc($sent);

        self::assertNotNull($parsed);
        self::assertSame($expected, $parsed->format('Y-m-d H:i'));
        self::assertSame('UTC', $parsed->getTimezone()->getName());
    }

    /**
     * The case that caused the bug, twice.
     *
     * A naked wall clock has no offset, so PHP reads it in the process timezone -
     * whatever that happens to be. Pinned by setting the default to Paris for the
     * length of the test: parsed there, `10:00` is `08:00Z`, and a copy of this
     * rule that forgot to convert stored `10:00` and drew the event two hours
     * late.
     */
    public function testANakedWallClockIsConvertedAndNotStored(): void
    {
        $was = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $parsed = PlanningClock::utc('2026-09-01T10:00');

            self::assertNotNull($parsed);
            self::assertSame('2026-09-01 08:00', $parsed->format('Y-m-d H:i'));
        } finally {
            date_default_timezone_set($was);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonInstants(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ["  \t "];
        yield 'not a date' => ['pas une date'];
        yield 'an integer' => [1_756_713_600];
        yield 'an array' => [['2026-09-01']];
    }

    /**
     * Anything unusable is null, because it came from a form.
     *
     * An exception here would be a 500 for a typo. The caller turns null into a
     * field error, which is the answer the person filling it in can act on.
     */
    #[DataProvider('nonInstants')]
    public function testAnythingUnusableIsNull(mixed $value): void
    {
        self::assertNull(PlanningClock::utc($value));
    }

    public function testACalendarsZoneIsItsOwn(): void
    {
        $planning = new Planning();
        $planning->setTimezone('Europe/Paris');

        self::assertSame('Europe/Paris', PlanningClock::zone($planning)->getName());
    }

    /**
     * A zone the database no longer knows falls back rather than throwing.
     *
     * Refusing the save would be worse: the reader cannot fix a timezone that was
     * valid when they picked it, and UTC is what the column holds anyway.
     */
    public function testAZoneThatNoLongerExistsFallsBackToUtc(): void
    {
        $planning = new Planning();
        $planning->setTimezone('Mars/Olympus_Mons');

        self::assertSame('UTC', PlanningClock::zone($planning)->getName());
    }
}
