<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Recurrence\OccurrenceExpander;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * What a rule produces, and what it must not.
 *
 * The behaviours here are the reason expansion goes through a library rather than
 * a reader of our own: each of them is a defect that hides for months in
 * hand-rolled code, and every one is asserted against a date somebody can check
 * on a wall calendar.
 */
final class OccurrenceExpanderTest extends TestCase
{
    private OccurrenceExpander $expander;

    protected function setUp(): void
    {
        $this->expander = new OccurrenceExpander();
    }

    /** @return list<string> the occurrences, on the calendar's own clock */
    private function starts(PlanningEvent $master, string $from, string $to, string $format = 'Y-m-d H:i'): array
    {
        $zone = new DateTimeZone($master->getPlanning()->getTimezone());

        return array_map(
            static fn ($occurrence): string => $occurrence->startAt->setTimezone($zone)->format($format),
            $this->expander->expand(
                $master,
                new DateTimeImmutable($from, new DateTimeZone('UTC')),
                new DateTimeImmutable($to, new DateTimeZone('UTC')),
            ),
        );
    }

    private function master(string $rrule, string $start, string $end, string $timezone = 'Europe/Paris'): PlanningEvent
    {
        $planning = new Planning();
        $planning->setName('Série');
        $planning->setTimezone($timezone);

        $zone = new DateTimeZone($timezone);
        $event = new PlanningEvent();
        $event->setPlanning($planning);
        $event->setTitle('Point hebdo');
        $event->setSpan(
            new DateTimeImmutable($start, $zone),
            new DateTimeImmutable($end, $zone),
        );
        $event->setRrule($rrule);

        return $event;
    }

    public function testASingleEventProducesNothing(): void
    {
        $event = $this->master('FREQ=WEEKLY', '2026-08-24 09:00', '2026-08-24 10:00');
        $event->setRrule(null);

        self::assertSame([], $this->starts($event, '2026-08-01', '2026-09-30'));
    }

    public function testAWeeklyRuleProducesOneOccurrenceAWeek(): void
    {
        $master = $this->master('FREQ=WEEKLY;BYDAY=MO', '2026-08-24 09:00', '2026-08-24 09:45');

        self::assertSame(
            ['2026-08-24 09:00', '2026-08-31 09:00', '2026-09-07 09:00'],
            $this->starts($master, '2026-08-24', '2026-09-13'),
        );
    }

    /**
     * The wall clock survives the clock change.
     *
     * "Every Monday at 09:00" means 09:00 where the calendar lives, whatever UTC
     * is doing that week. An expander working in UTC gives 08:00 for half the
     * year, and the reader finds their weekly meeting has moved an hour.
     */
    public function testAWeeklyRuleKeepsItsTimeAcrossTheClockChange(): void
    {
        $master = $this->master('FREQ=WEEKLY;BYDAY=SA', '2026-10-17 09:00', '2026-10-17 10:00');

        self::assertSame(
            ['2026-10-17 09:00 +02:00', '2026-10-24 09:00 +02:00', '2026-10-31 09:00 +01:00'],
            $this->starts($master, '2026-10-01', '2026-11-05', 'Y-m-d H:i P'),
        );
    }

    /**
     * The thirty-first skips the months that have none.
     *
     * Clamping to the 28th would put a monthly meeting on a day nobody chose, and
     * the standard says to skip. February and April are absent below on purpose.
     */
    public function testAMonthlyRuleOnTheThirtyFirstSkipsShortMonths(): void
    {
        $master = $this->master('FREQ=MONTHLY;BYMONTHDAY=31', '2026-01-31 09:00', '2026-01-31 10:00');

        self::assertSame(
            ['2026-01-31 09:00', '2026-03-31 09:00', '2026-05-31 09:00'],
            $this->starts($master, '2026-01-01', '2026-06-30'),
        );
    }

    public function testAnOccurrenceIsAsLongAsTheEvent(): void
    {
        $master = $this->master('FREQ=DAILY', '2026-08-24 09:00', '2026-08-24 09:45');

        $occurrences = $this->expander->expand(
            $master,
            new DateTimeImmutable('2026-08-24', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-26', new DateTimeZone('UTC')),
        );

        foreach ($occurrences as $occurrence) {
            self::assertSame(
                45 * 60,
                $occurrence->endAt->getTimestamp() - $occurrence->startAt->getTimestamp(),
            );
        }
    }

    public function testEveryOccurrenceKnowsWhichDateItIs(): void
    {
        // The identity a client sends back to say "this one". Without it, editing
        // one occurrence of a weekly meeting cannot be told from editing another.
        $master = $this->master('FREQ=DAILY;COUNT=2', '2026-08-24 09:00', '2026-08-24 10:00');

        foreach ($this->expander->expand(
            $master,
            new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-31', new DateTimeZone('UTC')),
        ) as $occurrence) {
            self::assertTrue($occurrence->isGenerated());
            self::assertSame($occurrence->startAt->format(DATE_ATOM), $occurrence->occurrenceAt?->format(DATE_ATOM));
        }
    }

    public function testADeletedOccurrenceStopsBeingProduced(): void
    {
        $master = $this->master('FREQ=WEEKLY;BYDAY=MO', '2026-08-24 09:00', '2026-08-24 10:00');
        $master->excludeOccurrence(new DateTimeImmutable('2026-08-31 09:00', new DateTimeZone('Europe/Paris')));

        self::assertSame(
            ['2026-08-24 09:00', '2026-09-07 09:00'],
            $this->starts($master, '2026-08-24', '2026-09-13'),
        );
    }

    /**
     * An edited occurrence is not generated twice.
     *
     * It has become a row of its own, returned by the query that finds every other
     * row - so the series has to stop emitting the date it replaced, or the reader
     * sees the meeting both where it was and where it moved to.
     */
    public function testAnEditedOccurrenceIsNotAlsoGenerated(): void
    {
        $master = $this->master('FREQ=WEEKLY;BYDAY=MO', '2026-08-24 09:00', '2026-08-24 10:00');

        $moved = new PlanningEvent();
        $moved->setPlanning($master->getPlanning());
        $moved->setTitle('Point hebdo, déplacé');
        $moved->setSpan(
            new DateTimeImmutable('2026-08-31 14:00', new DateTimeZone('Europe/Paris')),
            new DateTimeImmutable('2026-08-31 15:00', new DateTimeZone('Europe/Paris')),
        );
        $moved->setOccurrenceAt(new DateTimeImmutable('2026-08-31 09:00', new DateTimeZone('Europe/Paris')));
        $master->getOccurrences()->add($moved);

        self::assertSame(
            ['2026-08-24 09:00', '2026-09-07 09:00'],
            $this->starts($master, '2026-08-24', '2026-09-13'),
        );
    }

    public function testItProducesNothingOutsideTheWindow(): void
    {
        $master = $this->master('FREQ=WEEKLY;BYDAY=MO', '2026-08-24 09:00', '2026-08-24 10:00');

        self::assertSame([], $this->starts($master, '2026-06-01', '2026-06-30'));
    }

    /**
     * A rule the library refuses empties itself, not the month.
     *
     * One malformed row must not take every other event on the calendar with it.
     */
    public function testAnUnreadableRuleProducesNothingRatherThanThrowing(): void
    {
        $master = $this->master('FREQ=CHAQUE_MARDI', '2026-08-24 09:00', '2026-08-24 10:00');

        self::assertSame([], $this->starts($master, '2026-08-01', '2026-09-30'));
    }

    public function testASeriesThatEndsKnowsWhenItEnds(): void
    {
        $master = $this->master('FREQ=WEEKLY;BYDAY=MO;COUNT=3', '2026-08-24 09:00', '2026-08-24 10:00');

        self::assertSame(
            '2026-09-07 09:00',
            $this->expander->lastStart($master)?->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'),
        );
    }

    /**
     * An endless series has no last occurrence, and says so.
     *
     * Returning the library's virtual limit instead would be a number about the
     * library rather than about the series - and it would go into the column the
     * window query trusts.
     */
    public function testAnEndlessSeriesHasNoLastOccurrence(): void
    {
        $master = $this->master('FREQ=WEEKLY;BYDAY=MO', '2026-08-24 09:00', '2026-08-24 10:00');

        self::assertNull($this->expander->lastStart($master));
    }

    public function testARuleWithAnUntilStopsThere(): void
    {
        $master = $this->master('FREQ=WEEKLY;BYDAY=MO;UNTIL=20260907T070000Z', '2026-08-24 09:00', '2026-08-24 10:00');

        self::assertSame(
            ['2026-08-24 09:00', '2026-08-31 09:00', '2026-09-07 09:00'],
            $this->starts($master, '2026-08-01', '2026-10-31'),
        );
    }

    /**
     * A rule nobody meant to write cannot make a request that never returns.
     *
     * Hourly over a month is 720 occurrences and a screen cannot use them.
     */
    public function testItRefusesToProduceMoreThanAWindowCanHold(): void
    {
        $master = $this->master('FREQ=HOURLY', '2026-01-01 00:00', '2026-01-01 01:00');

        $count = count($this->starts($master, '2026-01-01', '2026-12-31'));

        self::assertLessThanOrEqual(750, $count);
        self::assertGreaterThan(0, $count);
    }
}
