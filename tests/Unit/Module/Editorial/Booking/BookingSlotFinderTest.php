<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Booking;

use Aurora\Core\Scheduling\Availability\ScheduleAvailabilityInterface;
use Aurora\Module\Editorial\Booking\Service\BookingSlotFinder;
use Aurora\Module\Editorial\Post\Grid\GridZoneOptions;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The slots a booking zone offers, and the one question a submission asks
 * again before it writes anything.
 */
final class BookingSlotFinderTest extends TestCase
{
    private const array HOURS = [
        'mon' => [], 'tue' => [['09:00', '12:00']], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [],
    ];

    public function testSlotsAreQuantisedToTheirDurationInsideTheOpenHours(): void
    {
        // 22 September 2026 is a Tuesday.
        $finder = $this->finder([]);

        $options = GridZoneOptions::normalize(['hours' => self::HOURS, 'slotDuration' => 60, 'bookingWindowDays' => 7, 'timezone' => 'Europe/Paris']);
        $days = $finder->days($options, 'fr');

        self::assertCount(1, $days);
        self::assertSame('2026-09-22', $days[0]['date']);
        self::assertSame(['09:00', '10:00', '11:00'], array_map(static fn (array $slot): string => mb_substr($slot['at'], 11, 5), $days[0]['slots']));
    }

    public function testABusySlotIsNotOffered(): void
    {
        $finder = $this->finder([[
            new DateTimeImmutable('2026-09-22 10:00:00 Europe/Paris'),
            new DateTimeImmutable('2026-09-22 11:00:00 Europe/Paris'),
        ]]);
        $options = GridZoneOptions::normalize(['hours' => self::HOURS, 'slotDuration' => 60, 'bookingWindowDays' => 7, 'timezone' => 'Europe/Paris']);

        self::assertSame(['09:00', '11:00'], array_map(
            static fn (array $slot): string => mb_substr($slot['at'], 11, 5),
            $finder->days($options, 'fr')[0]['slots'],
        ));
    }

    /** A span that only overlaps a slot's edge takes it all the same. */
    public function testASlotOverlappedAtAllIsNotFree(): void
    {
        $finder = $this->finder([[
            new DateTimeImmutable('2026-09-22 10:30:00 Europe/Paris'),
            new DateTimeImmutable('2026-09-22 10:45:00 Europe/Paris'),
        ]]);

        self::assertFalse($finder->isFree(new DateTimeImmutable('2026-09-22 10:00:00 Europe/Paris'), new DateTimeImmutable('2026-09-22 11:00:00 Europe/Paris')));
        self::assertTrue($finder->isFree(new DateTimeImmutable('2026-09-22 10:45:00 Europe/Paris'), new DateTimeImmutable('2026-09-22 11:45:00 Europe/Paris')), 'touching is not overlapping');
    }

    public function testOnlyAStartTheGridOffersIsBookable(): void
    {
        $finder = $this->finder([]);
        $options = GridZoneOptions::normalize(['hours' => self::HOURS, 'slotDuration' => 60, 'bookingWindowDays' => 7, 'timezone' => 'Europe/Paris']);
        $at = static fn (string $moment): DateTimeImmutable => new DateTimeImmutable($moment.' Europe/Paris');

        self::assertTrue($finder->isOffered($options, $at('2026-09-22 11:00')), 'the last slot of the morning');
        self::assertTrue($finder->isOffered($options, $at('2026-09-22 09:00')->setTimezone(new DateTimeZone('UTC'))), 'the same instant in another timezone');
        self::assertFalse($finder->isOffered($options, $at('2026-09-22 10:17')), 'off the slot grid');
        self::assertFalse($finder->isOffered($options, $at('2026-09-22 11:30')), 'would end after closing');
        self::assertFalse($finder->isOffered($options, $at('2026-09-23 10:00')), 'a closed day');
        self::assertFalse($finder->isOffered($options, $at('2026-09-29 10:00')), 'beyond the seven-day window');
        self::assertFalse($finder->isOffered($options, $at('2026-09-21 09:00')), 'in the past');
    }

    public function testAClosedDateOffersNothing(): void
    {
        $finder = $this->finder([]);
        $options = GridZoneOptions::normalize(['hours' => self::HOURS, 'slotDuration' => 60, 'bookingWindowDays' => 7, 'timezone' => 'Europe/Paris', 'closedDates' => ['2026-09-22']]);

        self::assertFalse($finder->isOffered($options, new DateTimeImmutable('2026-09-22 10:00 Europe/Paris')));
    }

    /**
     * The service on a calendar holding these spans, at 08:00 in Paris on
     * Monday 21 September 2026.
     *
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $busy
     */
    private function finder(array $busy): BookingSlotFinder
    {
        $availability = $this->createStub(ScheduleAvailabilityInterface::class);
        $availability->method('busyPeriods')->willReturn($busy);

        return new BookingSlotFinder($availability, new MockClock(new DateTimeImmutable('2026-09-21 06:00:00 UTC')));
    }
}
