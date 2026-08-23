<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Repository;

use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminderInterface;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningReminder>
 */
class PlanningReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningReminder::class);
    }

    /**
     * The reminders of some calendars falling inside a window.
     *
     * A single instant rather than the span an event has, so the comparison is
     * `>= from AND < to` and not the overlap test events need. Simpler, and worth
     * saying: an event's window bug is subtle and this one has no room for it.
     *
     * @param list<int> $planningIds
     *
     * @return list<PlanningReminderInterface>
     */
    public function findInWindow(array $planningIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ([] === $planningIds) {
            return [];
        }

        /** @var list<PlanningReminderInterface> $result */
        $result = $this->windowQuery($planningIds, $from, $to)->getQuery()->getResult();

        return $result;
    }

    /**
     * What the worker should announce now.
     *
     * Three conditions, and each one is a bug if it is missing: due, or it fires
     * early; not yet announced, or it fires every minute for ever; not done, or
     * ticking something off does not stop it arriving.
     *
     * No lower bound on lateness, like the alerts: a worker stopped for an hour
     * comes back to an hour of reminders and sends them. One arriving late is
     * information; one that never arrives is a bug reported as "it did not work".
     *
     * @return list<PlanningReminderInterface>
     */
    public function findDue(DateTimeImmutable $now, int $limit = 200): array
    {
        /** @var list<PlanningReminderInterface> $result */
        $result = $this->createQueryBuilder('r')
            ->addSelect('p')
            // Joined, because the notification needs the calendar's name and its
            // owner - lazy-loading them would be two queries per reminder.
            ->innerJoin('r.planning', 'p')
            ->where('r.dueAt <= :now')
            ->andWhere('r.notifiedAt IS NULL')
            ->andWhere('r.completedAt IS NULL')
            ->setParameter('now', $now)
            ->orderBy('r.dueAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** @param list<int> $planningIds */
    private function windowQuery(array $planningIds, DateTimeImmutable $from, DateTimeImmutable $to): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->addSelect('p')
            ->innerJoin('r.planning', 'p')
            ->where('r.planning IN (:plannings)')
            ->andWhere('r.dueAt >= :from')
            ->andWhere('r.dueAt < :to')
            ->setParameter('plannings', $planningIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.dueAt', 'ASC');
    }
}
