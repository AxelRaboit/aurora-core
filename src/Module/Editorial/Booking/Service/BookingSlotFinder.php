<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Booking\Service;

use Aurora\Core\Locale\Service\LocaleContextInterface;
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
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_any;
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

    private const string NAME_KEY = 'frontend.editorial.grid.booking.calendar_name';

    public function __construct(
        private ModuleCalendarProvider $calendars,
        private PlanningEventRepository $events,
        private TranslatorInterface $translator,
        private LocaleContextInterface $localeContext,
        private ?ClockInterface $clock = null,
    ) {}

    /**
     * The site's one bookings calendar, named in the site's default language.
     *
     * Not the visitor's: the provider renames a calendar whose name changed,
     * and a French visitor after a Spanish one flipped it between
     * « Réservations en ligne » and « Reservas en línea » all day long. The
     * calendar lives in the back office, whose name should hold still.
     */
    public function calendar(): PlanningInterface
    {
        return $this->calendars->forSource(
            self::SOURCE,
            $this->translator->trans(self::NAME_KEY, [], 'messages', $this->localeContext->getDefaultLocale()),
        );
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
    public function isFree(DateTimeImmutable $start, DateTimeImmutable $end, PlanningInterface $planning): bool
    {
        $busy = array_filter(
            $this->events->findSinglesInWindow([(int) $planning->getId()], $start, $end),
            static fn (PlanningEventInterface $event): bool => PlanningEventStatusEnum::Cancelled !== $event->getStatus(),
        );

        return [] === $busy;
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
