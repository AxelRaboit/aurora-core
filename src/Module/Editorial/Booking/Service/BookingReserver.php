<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Booking\Service;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Scheduling\Event\EntityScheduledEvent;
use Aurora\Module\Editorial\Booking\Entity\Booking;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes a booking only if its slot is still free, one booking at a time,
 * and announces it to the calendar.
 *
 * Checking and then writing is two steps, and two visitors who pick the same
 * slot in the same second both pass the check before either writes: the
 * calendar ended up with two tentative events on one slot. So both steps run
 * in one transaction holding a PostgreSQL advisory lock keyed on this
 * feature. The second request waits for the first to commit, then sees its
 * entry and is told the slot is taken.
 *
 * The lock covers every booking, not one slot: bookings are rare and short,
 * and two slots overlap in ways a per-slot key would miss (a 30-minute slot
 * inside a 60-minute one). It is released at commit or rollback, never held
 * across requests, and it touches no table, so nothing else waits.
 *
 * The calendar entry is made by announcing the booking through core's
 * {@see EntityScheduledEvent}, inside the same transaction: the calendar
 * writes it before the lock is released, so the next request sees it.
 */
final readonly class BookingReserver
{
    /** "Book", so this lock cannot collide with another feature's advisory locks. */
    public const int LOCK_NAMESPACE = 0x426F6F6B;

    private const string NAME_KEY = 'frontend.editorial.grid.booking.calendar_name';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingSlotFinder $slots,
        private EventDispatcherInterface $dispatcher,
        private TranslatorInterface $translator,
        private LocaleContextInterface $localeContext,
    ) {}

    /**
     * Whether the booking was written; false when its slot was taken first.
     *
     * @param string      $name        who booked, the calendar entry's title
     * @param string      $description how to reach them, and what they said
     * @param string|null $url         the page the booking was made from
     */
    public function reserve(Booking $booking, string $name, string $description, ?string $url): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($booking, $name, $description, $url): bool {
            $this->lock();

            if (!$this->slots->isFree($booking->getStartAt(), $booking->getEndAt())) {
                return false;
            }

            $this->entityManager->persist($booking);
            $this->entityManager->flush();

            $this->dispatcher->dispatch(new EntityScheduledEvent(
                BookingSlotFinder::SOURCE,
                (int) $booking->getId(),
                $name,
                $booking->getStartAt(),
                $booking->getEndAt(),
                $this->calendarName(),
                $name,
                $url,
                description: $description,
                tentative: true,
                editable: true,
            ));

            return true;
        });
    }

    /**
     * The bookings calendar's name, in the site's default language.
     *
     * Not the visitor's: the calendar renames itself when the name it is
     * given changes, and a French visitor after a Spanish one flipped it
     * between « Réservations en ligne » and « Reservas en línea » all day
     * long. The calendar lives in the back office, whose name should hold
     * still.
     */
    private function calendarName(): string
    {
        return $this->translator->trans(self::NAME_KEY, [], 'messages', $this->localeContext->getDefaultLocale());
    }

    private function lock(): void
    {
        $connection = $this->entityManager->getConnection();

        // Aurora runs on PostgreSQL; another platform keeps the check without
        // the lock rather than failing on a function it does not have.
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $connection->executeStatement('SELECT pg_advisory_xact_lock(?)', [self::LOCK_NAMESPACE]);
    }
}
