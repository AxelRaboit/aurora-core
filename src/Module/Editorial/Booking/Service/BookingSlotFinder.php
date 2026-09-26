<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Booking\Service;

use Aurora\Module\Editorial\Post\Grid\GridZoneOptions;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Sync\Manager\ModuleCalendarProvider;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Psr\Clock\ClockInterface;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function max;
use function mb_strtoupper;
use function mb_substr;
use function sprintf;

/**
 * Which slots of an appointment-booking zone are still free.
 *
 * One calendar for the whole site, made on first use by
 * {@see ModuleCalendarProvider}: every zone's bookings land in the same
 * place, which is where the back office already looks for a calendar.
 *
 * A slot is free when it falls inside the zone's opening hours, is not on a
 * day marked closed, starts at least an hour from now, and does not overlap
 * an event already on that calendar - any status but cancelled, because a
 * tentative booking holds its slot exactly as a confirmed one does.
 */
final readonly class BookingSlotFinder
{
    private const string SOURCE = 'editorial.booking';

    /** How far ahead a slot must start, so nobody books the next five minutes. */
    private const int LEAD_MINUTES = 60;

    public function __construct(
        private ModuleCalendarProvider $calendars,
        private PlanningEventRepository $events,
        private ?ClockInterface $clock = null,
    ) {}

    public function calendar(string $name): PlanningInterface
    {
        return $this->calendars->forSource(self::SOURCE, $name);
    }

    /**
     * @param array<string, mixed> $options a zone's normalised options
     *
     * @return list<array{date: string, label: string, slots: list<array{at: string, label: string}>}>
     */
    public function days(array $options, PlanningInterface $planning, string $locale): array
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

        $busy = array_values(array_filter(
            $this->events->findSinglesInWindow([(int) $planning->getId()], $from, $to),
            static fn (PlanningEventInterface $event): bool => PlanningEventStatusEnum::Cancelled !== $event->getStatus(),
        ));

        $weekdayFormat = new IntlDateFormatter($locale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, $timezone);
        $timeFormat = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $timezone);
        $days = [];

        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $key = $this->weekday($day);
            $ranges = $options['hours'][$key];
            if ([] === $ranges) {
                continue;
            }

            if (in_array($day->format('Y-m-d'), $options['closedDates'], true)) {
                continue;
            }

            $slots = [];
            foreach ($ranges as [$open, $close]) {
                $slot = $this->atClock($day, $open, $timezone);
                $end = $this->atClock($day, $close, $timezone);

                while ($slot->add(new DateInterval(sprintf('PT%dM', $duration))) <= $end) {
                    $slotEnd = $slot->add(new DateInterval(sprintf('PT%dM', $duration)));

                    if ($slot >= $earliest && !$this->overlaps($slot, $slotEnd, $busy)) {
                        $slots[] = ['at' => $slot->format(DATE_ATOM), 'label' => (string) $timeFormat->format($slot)];
                    }

                    $slot = $slotEnd;
                }
            }

            if ([] !== $slots) {
                $days[] = ['date' => $day->format('Y-m-d'), 'label' => $this->capitalise((string) $weekdayFormat->format($day)), 'slots' => $slots];
            }
        }

        return $days;
    }

    /**
     * Whether the slot the browser posted is still free - the same rule
     * `days()` draws its grid from, asked about one instant instead of
     * every one of them, so the two cannot disagree.
     */
    public function isFree(DateTimeImmutable $start, DateTimeImmutable $end, PlanningInterface $planning): bool
    {
        $busy = array_filter(
            $this->events->findSinglesInWindow([(int) $planning->getId()], $start, $end),
            static fn (PlanningEventInterface $event): bool => PlanningEventStatusEnum::Cancelled !== $event->getStatus(),
        );

        return [] === $busy;
    }

    private function atClock(DateTimeImmutable $day, string $clock, DateTimeZone $timezone): DateTimeImmutable
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', '24:00' === $clock ? '23:59' : $clock));

        $at = $day->setTime($hours, $minutes);

        return '24:00' === $clock ? $at->modify('+1 minute') : $at;
    }

    /** @param list<PlanningEventInterface> $busy */
    private function overlaps(DateTimeImmutable $start, DateTimeImmutable $end, array $busy): bool
    {
        return array_any($busy, fn ($event): bool => $start < $event->getEndAt() && $end > $event->getStartAt());
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
