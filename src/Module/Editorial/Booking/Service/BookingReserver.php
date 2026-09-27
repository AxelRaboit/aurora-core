<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Booking\Service;

use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a booking only if its slot is still free, one booking at a time.
 *
 * Checking and then writing is two steps, and two visitors who pick the same
 * slot in the same second both pass the check before either writes: the
 * calendar ended up with two tentative events on one slot. So both steps run
 * in one transaction holding a lock on the calendar - a PostgreSQL advisory
 * lock, keyed on this feature and the calendar's id. The second request waits
 * for the first to commit, then sees its event and is told the slot is taken.
 *
 * The lock is the calendar's, not the slot's: bookings are rare and short, and
 * two slots overlap in ways a per-slot key would miss (a 30-minute slot inside
 * a 60-minute one). It is released at commit or rollback, never held across
 * requests, and it touches no table, so nothing else on the calendar waits.
 */
final readonly class BookingReserver
{
    /** "Book", so this lock cannot collide with another feature's advisory locks. */
    public const int LOCK_NAMESPACE = 0x426F6F6B;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingSlotFinder $slots,
    ) {}

    /** Whether the booking was written; false when its slot was taken first. */
    public function reserve(PlanningEventInterface $event): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($event): bool {
            $this->lockCalendar((int) $event->getPlanning()->getId());

            if (!$this->slots->isFree($event->getStartAt(), $event->getEndAt(), $event->getPlanning())) {
                return false;
            }

            $this->entityManager->persist($event);

            return true;
        });
    }

    private function lockCalendar(int $planningId): void
    {
        $connection = $this->entityManager->getConnection();

        // Aurora runs on PostgreSQL; another platform keeps the check without
        // the lock rather than failing on a function it does not have.
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $connection->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $planningId]);
    }
}
