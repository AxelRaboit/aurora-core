<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Booking\Service;

use Aurora\Core\Scheduling\Availability\ScheduleAvailabilityInterface;
use Aurora\Module\Editorial\Post\Grid\GridZoneOptions;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Psr\Clock\ClockInterface;

use function array_any;
use function array_map;
use function explode;
use function in_array;
use function max;
use function mb_strtoupper;
use function mb_substr;
use function sprintf;

/**
 * Which slots of an appointment-booking zone are still free.
 *
 * Asked of the calendar through core's {@see ScheduleAvailabilityInterface},
 * never of the calendar module itself: every zone's bookings land in one
 * calendar, and what is taken there - bookings and whatever the owner
 * blocked by hand - is the calendar's to say.
 *
 * A slot is free when it falls inside the zone's opening hours, is not on a
 * day marked closed, starts at least an hour from now, and does not overlap
 * anything already taken - a tentative booking holds its slot exactly as a
 * confirmed one does.
 */
final readonly class BookingSlotFinder
{
    /** The source every booking is announced under, and the calendar's key. */
    public const string SOURCE = 'editorial.booking';

    /** How far ahead a slot must start, so nobody books the next five minutes. */
    private const int LEAD_MINUTES = 60;

    public function __construct(
        private ScheduleAvailabilityInterface $availability,
        private ?ClockInterface $clock = null,
    ) {}

    /**
     * Whether a booking taken now would reach a calendar. With the calendar
     * switched off it would land nowhere, so the zone offers nothing.
     */
    public function isEnabled(): bool
    {
        return $this->availability->isEnabled();
    }

    /**
     * @param array<string, mixed> $options a zone's normalised options
     *
     * @return list<array{date: string, label: string, slots: list<array{at: string, label: string}>}>
     */
    public function days(array $options, string $locale): array
    {
        $timezone = new DateTimeZone($options['timezone']);
        $now = ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone($timezone);
        $earliest = $now->modify(sprintf('+%d minutes', self::LEAD_MINUTES));
        $span = max(1, (int) $options['bookingWindowDays']);
        $duration = max(5, (int) $options['slotDuration']);

        // Midnight of "now" in the zone's own timezone - not a fresh "today",
        // which would read the system clock instead of the one this service
        // was given, and silently ignore it in every test that injects one.
        $from = $now->setTime(0, 0);
        $to = $from->modify(sprintf('+%d days', $span));

        $busy = $this->availability->busyPeriods(self::SOURCE, $from, $to);

        $weekdayFormat = new IntlDateFormatter($locale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, $timezone);
        $timeFormat = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $timezone);
        $days = [];

        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $slots = [];
            foreach ($this->gridOf($day, $options, $timezone, $duration) as $slot) {
                $slotEnd = $slot->add(new DateInterval(sprintf('PT%dM', $duration)));

                if ($slot >= $earliest && !$this->overlaps($slot, $slotEnd, $busy)) {
                    $slots[] = ['at' => $slot->format(DATE_ATOM), 'label' => (string) $timeFormat->format($slot)];
                }
            }

            if ([] !== $slots) {
                $days[] = ['date' => $day->format('Y-m-d'), 'label' => $this->capitalise((string) $weekdayFormat->format($day)), 'slots' => $slots];
            }
        }

        return $days;
    }

    /**
     * Whether the grid could have offered this start at all: inside the
     * booking window, far enough ahead, on the slot grid of an open day.
     *
     * Asked before `isFree()`, so a forged instant - 10:17, a start that
     * would end after closing, a date years ahead - is refused even when the
     * calendar happens to be empty then.
     *
     * @param array<string, mixed> $options a zone's normalised options
     */
    public function isOffered(array $options, DateTimeImmutable $start): bool
    {
        $timezone = new DateTimeZone($options['timezone']);
        $now = ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone($timezone);
        $start = $start->setTimezone($timezone);
        $from = $now->setTime(0, 0);
        $to = $from->modify(sprintf('+%d days', max(1, (int) $options['bookingWindowDays'])));

        if ($start < $now->modify(sprintf('+%d minutes', self::LEAD_MINUTES)) || $start >= $to) {
            return false;
        }

        $duration = max(5, (int) $options['slotDuration']);

        return array_any(
            $this->gridOf($start->setTime(0, 0), $options, $timezone, $duration),
            static fn (DateTimeImmutable $slot): bool => $slot->getTimestamp() === $start->getTimestamp(),
        );
    }

    /**
     * Whether the slot the browser posted is still free - the same rule
     * `days()` draws its grid from, asked about one instant instead of
     * every one of them, so the two cannot disagree.
     */
    public function isFree(DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        return !$this->overlaps($start, $end, $this->availability->busyPeriods(self::SOURCE, $start, $end));
    }

    /**
     * Every slot start of one day, busy or not: the opening hours cut into
     * slots that end before closing. Empty on a closed day.
     *
     * @param array<string, mixed> $options
     *
     * @return list<DateTimeImmutable>
     */
    private function gridOf(DateTimeImmutable $day, array $options, DateTimeZone $timezone, int $duration): array
    {
        if (in_array($day->format('Y-m-d'), $options['closedDates'], true)) {
            return [];
        }

        $starts = [];
        foreach ($options['hours'][$this->weekday($day)] as [$open, $close]) {
            $slot = $this->atClock($day, $open, $timezone);
            $end = $this->atClock($day, $close, $timezone);

            while ($slot->add(new DateInterval(sprintf('PT%dM', $duration))) <= $end) {
                $starts[] = $slot;
                $slot = $slot->add(new DateInterval(sprintf('PT%dM', $duration)));
            }
        }

        return $starts;
    }

    private function atClock(DateTimeImmutable $day, string $clock, DateTimeZone $timezone): DateTimeImmutable
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', '24:00' === $clock ? '23:59' : $clock));

        $at = $day->setTime($hours, $minutes);

        return '24:00' === $clock ? $at->modify('+1 minute') : $at;
    }

    /** @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $busy */
    private function overlaps(DateTimeImmutable $start, DateTimeImmutable $end, array $busy): bool
    {
        return array_any($busy, static fn (array $span): bool => $start < $span[1] && $end > $span[0]);
    }

    private function weekday(DateTimeImmutable $date): string
    {
        return GridZoneOptions::WEEKDAYS[(int) $date->format('N') - 1];
    }

    private function capitalise(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
