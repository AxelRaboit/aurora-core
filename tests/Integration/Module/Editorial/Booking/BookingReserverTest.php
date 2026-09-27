<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Booking;

use Aurora\Core\Locale\Service\LocaleContext;
use Aurora\Module\Editorial\Booking\Service\BookingReserver;
use Aurora\Module\Editorial\Booking\Service\BookingSlotFinder;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function random_int;
use function sprintf;

/**
 * Two visitors on the same slot in the same second: one booking, not two.
 *
 * A race cannot be staged reliably inside one PHP process, so the test plays
 * the other visitor itself - a second connection that holds the calendar's
 * lock, as a booking in flight does - and checks that this one waits for it
 * instead of writing alongside.
 */
final class BookingReserverTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    /** A day of its own per test, far ahead, on the calendar every run shares. */
    private DateTimeImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))
            ->modify(sprintf('+%d days', 400 + random_int(0, 20000)));
        static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testASecondBookingOnTheSameSlotIsRefused(): void
    {
        $planning = $this->calendar();
        $reserver = static::getContainer()->get(BookingReserver::class);

        self::assertTrue($reserver->reserve($this->booking($planning, '10:00')));
        self::assertFalse($reserver->reserve($this->booking($planning, '10:00')), 'the slot was taken first');
        self::assertTrue($reserver->reserve($this->booking($planning, '11:00')), 'the next slot is still free');
    }

    /**
     * Named in the site's default language, whoever books: the name used to
     * follow the visitor's, and the shared calendar was renamed back and forth.
     */
    public function testTheCalendarKeepsTheSitesLanguageWhateverTheVisitors(): void
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $translator->setLocale('en');

        $name = static::getContainer()->get(BookingSlotFinder::class)->calendar()->getName();
        $default = static::getContainer()->get(LocaleContext::class)->getDefaultLocale();

        self::assertSame($translator->trans('frontend.editorial.grid.booking.calendar_name', [], 'messages', $default), $name);
        self::assertNotSame('Online bookings', $name, 'the visitor language no longer names it');
    }

    /** While another booking holds the calendar, this one waits rather than writes. */
    public function testABookingWaitsForTheOneInFlight(): void
    {
        $planning = $this->calendar();
        $connection = $this->entityManager->getConnection();
        $other = DriverManager::getConnection($connection->getParams());
        $other->beginTransaction();
        $other->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [BookingReserver::LOCK_NAMESPACE, (int) $planning->getId()]);

        $connection->executeStatement("SET lock_timeout = '300ms'");
        $booking = $this->booking($planning, '14:00');

        try {
            static::getContainer()->get(BookingReserver::class)->reserve($booking);
            self::fail('The booking was written while another held the calendar.');
        } catch (DriverException $exception) {
            // 55P03, lock_not_available: it waited on the calendar's lock.
            self::assertSame('55P03', $exception->getSQLState());
        } finally {
            $other->rollBack();
            $other->close();
            $connection->executeStatement('SET lock_timeout = 0');
        }

        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM core_planning_events WHERE planning_id = ? AND start_at = ?',
            [(int) $planning->getId(), $booking->getStartAt()->format('Y-m-d H:i:sP')],
        ));
    }

    /** The site's one bookings calendar: every zone's bookings share it. */
    private function calendar(): PlanningInterface
    {
        return static::getContainer()->get(BookingSlotFinder::class)->calendar();
    }

    private function booking(PlanningInterface $planning, string $clock): PlanningEvent
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $clock));
        $start = $this->day->setTime($hours, $minutes);

        $event = new PlanningEvent();
        $event->setPlanning($planning)
            ->setTitle('Camille')
            ->setSpan($start, $start->modify('+60 minutes'))
            ->setStatus(PlanningEventStatusEnum::Tentative);

        return $event;
    }
}
