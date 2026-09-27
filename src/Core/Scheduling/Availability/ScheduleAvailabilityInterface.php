<?php

declare(strict_types=1);

namespace Aurora\Core\Scheduling\Availability;

use Aurora\Core\Scheduling\Event\EntityScheduledEvent;
use DateTimeImmutable;

/**
 * What is already taken on the calendar a module's dates land in.
 *
 * The read half of {@see EntityScheduledEvent}:
 * a module that offers slots - a booking zone - has to know which ones are
 * gone, events typed by hand in that calendar included, without reaching into
 * the calendar module to find out. Implemented there, asked here.
 */
interface ScheduleAvailabilityInterface
{
    /**
     * Whether dates announced now would land anywhere. False when the calendar
     * is switched off: a booking taken then would vanish without a trace.
     */
    public function isEnabled(): bool;

    /**
     * The spans already taken on the calendar that holds this source's dates,
     * cancelled entries left out, between two instants.
     *
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    public function busyPeriods(string $sourceType, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
