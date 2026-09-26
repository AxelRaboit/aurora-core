<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Booking;

use Aurora\Module\Editorial\Booking\Service\BookingSlotFinder;
use Aurora\Module\Editorial\Post\Grid\GridZoneOptions;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Aurora\Module\Planning\Sync\Manager\ModuleCalendarProvider;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
        $planning = new Planning();
        $events = $this->createStub(PlanningEventRepository::class);
        $events->method('findSinglesInWindow')->willReturn([]);

        $finder = new BookingSlotFinder(
            $this->calendars($planning),
            $events,
            new MockClock(new DateTimeImmutable('2026-09-21 06:00:00 UTC')),
        );

        $options = GridZoneOptions::normalize(['hours' => self::HOURS, 'slotDuration' => 60, 'bookingWindowDays' => 7, 'timezone' => 'Europe/Paris']);
        $days = $finder->days($options, $planning, 'fr');

        self::assertCount(1, $days);
        self::assertSame('2026-09-22', $days[0]['date']);
        self::assertSame(['09:00', '10:00', '11:00'], array_map(static fn (array $slot): string => mb_substr($slot['at'], 11, 5), $days[0]['slots']));
    }

    public function testABusySlotIsNotOffered(): void
    {
        $planning = new Planning();
        $busyEvent = new PlanningEvent();
        $busyEvent->setPlanning($planning)->setTitle('x')->setSpan(
            new DateTimeImmutable('2026-09-22 10:00:00 Europe/Paris'),
            new DateTimeImmutable('2026-09-22 11:00:00 Europe/Paris'),
        );

        $events = $this->createStub(PlanningEventRepository::class);
        $events->method('findSinglesInWindow')->willReturn([$busyEvent]);

        $finder = new BookingSlotFinder($this->calendars($planning), $events, new MockClock(new DateTimeImmutable('2026-09-21 06:00:00 UTC')));
        $options = GridZoneOptions::normalize(['hours' => self::HOURS, 'slotDuration' => 60, 'bookingWindowDays' => 7, 'timezone' => 'Europe/Paris']);

        self::assertSame(['09:00', '11:00'], array_map(
            static fn (array $slot): string => mb_substr($slot['at'], 11, 5),
            $finder->days($options, $planning, 'fr')[0]['slots'],
        ));
    }

    public function testACancelledEventFreesItsSlotBackUp(): void
    {
        $planning = new Planning();
        $cancelled = new PlanningEvent();
        $cancelled->setPlanning($planning)->setTitle('x')->setStatus(PlanningEventStatusEnum::Cancelled)->setSpan(
            new DateTimeImmutable('2026-09-22 10:00:00 Europe/Paris'),
            new DateTimeImmutable('2026-09-22 11:00:00 Europe/Paris'),
        );

        $events = $this->createStub(PlanningEventRepository::class);
        $events->method('findSinglesInWindow')->willReturn([$cancelled]);

        $finder = new BookingSlotFinder($this->calendars($planning), $events, new MockClock(new DateTimeImmutable('2026-09-21 06:00:00 UTC')));
        $options = GridZoneOptions::normalize(['hours' => self::HOURS, 'slotDuration' => 60, 'bookingWindowDays' => 7, 'timezone' => 'Europe/Paris']);

        self::assertTrue($finder->isFree(
            new DateTimeImmutable('2026-09-22 10:00:00 Europe/Paris'),
            new DateTimeImmutable('2026-09-22 11:00:00 Europe/Paris'),
            $planning,
        ));
    }

    /**
     * A real provider, unused dependencies: `days()` and `isFree()` are
     * given the planning directly, so `calendar()` is never called here -
     * and `ModuleCalendarProvider` is final, which rules out a double.
     */
    private function calendars(Planning $planning): ModuleCalendarProvider
    {
        return new ModuleCalendarProvider(
            $this->createStub(PlanningRepository::class),
            $this->createStub(EntityManagerInterface::class),
        );
    }
}
