<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Booking;

use Aurora\Core\Locale\Service\LocaleContext;
use Aurora\Module\Editorial\Booking\Entity\Booking;
use Aurora\Module\Editorial\Booking\Service\BookingReserver;
use Aurora\Module\Editorial\Booking\Service\BookingSlotFinder;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
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
 * the other visitor itself - a second connection that holds the bookings'
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
        $page = $this->page();
        $reserver = static::getContainer()->get(BookingReserver::class);

        self::assertTrue($reserver->reserve($this->booking($page, '10:00'), 'Camille', 'camille@example.com', null));
        self::assertFalse($reserver->reserve($this->booking($page, '10:00'), 'Un autre', 'autre@example.com', null), 'the slot was taken first');
        self::assertTrue($reserver->reserve($this->booking($page, '11:00'), 'Dominique', 'dominique@example.com', null), 'the next slot on the same page is still free');
    }

    /**
     * Named in the site's default language, whoever books: the name used to
     * follow the visitor's, and the shared calendar was renamed back and forth.
     */
    public function testTheCalendarKeepsTheSitesLanguageWhateverTheVisitors(): void
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $translator->setLocale('en');

        static::getContainer()->get(BookingReserver::class)->reserve($this->booking($this->page(), '12:00'), 'Camille', 'camille@example.com', null);

        $name = $this->calendar()->getName();
        $default = static::getContainer()->get(LocaleContext::class)->getDefaultLocale();

        self::assertSame($translator->trans('frontend.editorial.grid.booking.calendar_name', [], 'messages', $default), $name);
        self::assertNotSame('Online bookings', $name, 'the visitor language no longer names it');
    }

    /** While another booking is in flight, this one waits rather than writes. */
    public function testABookingWaitsForTheOneInFlight(): void
    {
        $connection = $this->entityManager->getConnection();
        $other = DriverManager::getConnection($connection->getParams());
        $other->beginTransaction();
        $other->executeStatement('SELECT pg_advisory_xact_lock(?)', [BookingReserver::LOCK_NAMESPACE]);

        $connection->executeStatement("SET lock_timeout = '300ms'");
        $booking = $this->booking($this->page(), '14:00');

        try {
            static::getContainer()->get(BookingReserver::class)->reserve($booking, 'Camille', 'camille@example.com', null);
            self::fail('The booking was written while another was in flight.');
        } catch (DriverException $exception) {
            // 55P03, lock_not_available: it waited on the bookings' lock.
            self::assertSame('55P03', $exception->getSQLState());
        } finally {
            $other->rollBack();
            $other->close();
            $connection->executeStatement('SET lock_timeout = 0');
        }

        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM core_editorial_bookings WHERE start_at = ?',
            [$booking->getStartAt()->format('Y-m-d H:i:s')],
        ));
    }

    /** The site's one bookings calendar: every zone's bookings share it. */
    private function calendar(): PlanningInterface
    {
        $calendar = static::getContainer()->get(PlanningRepository::class)->findOneBy(['sourceType' => BookingSlotFinder::SOURCE]);
        self::assertInstanceOf(PlanningInterface::class, $calendar);

        return $calendar;
    }

    private function booking(PostInterface $page, string $clock): Booking
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $clock));
        $start = $this->day->setTime($hours, $minutes);

        return new Booking($page, 'b1', $start, $start->modify('+60 minutes'));
    }

    private function page(): PostInterface
    {
        $type = new PostType();
        $type->setSlug('reservation-'.bin2hex(random_bytes(4)))->setLabel('Réservation')->setIcon('file-text')->setHasArchive(false);

        $post = new Post();
        $post->setPostType($type);
        $post->translate('fr')->setTitle('Réservation')->setSlug('reservation-'.bin2hex(random_bytes(4)));

        $this->entityManager->persist($type);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return $post;
    }
}
