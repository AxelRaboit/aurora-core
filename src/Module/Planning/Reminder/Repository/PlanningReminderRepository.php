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
     * Reminders whose title matches, among the calendars the reader may see.
     *
     * Completed ones included, unlike `findUpcoming`: searching is how you find
     * out whether you already did something.
     *
     * @param list<int> $planningIds
     *
     * @return list<PlanningReminderInterface>
     */
    public function searchVisible(array $planningIds, string $query, int $limit = 10): array
    {
        $term = mb_trim($query);
        if ([] === $planningIds || '' === $term) {
            return [];
        }

        /** @var list<PlanningReminderInterface> $result */
        $result = $this->createQueryBuilder('r')
            ->addSelect('p')
            ->innerJoin('r.planning', 'p')
            ->where('r.planning IN (:plannings)')
            ->andWhere('LOWER(r.title) LIKE :term')
            ->setParameter('plannings', $planningIds)
            ->setParameter('term', '%'.mb_strtolower($term).'%')
            ->orderBy('r.dueAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The next few reminders still to do.
     *
     * Completed ones are excluded, because "what is coming" means what is still
     * owed - a done reminder due tomorrow is not something to be reminded of.
     *
     * @param list<int> $planningIds
     *
     * @return list<PlanningReminderInterface>
     */
    public function findUpcoming(array $planningIds, DateTimeImmutable $from, int $limit = 5): array
    {
        if ([] === $planningIds) {
            return [];
        }

        /** @var list<PlanningReminderInterface> $result */
        $result = $this->createQueryBuilder('r')
            ->addSelect('p')
            ->innerJoin('r.planning', 'p')
            ->where('r.planning IN (:plannings)')
            // A whole-day reminder is still to come until its day is over,
            // the other half of `isOverdue()`: it is stored at the day's
            // start, and « from now on » left today's out of both lists.
            ->andWhere('(r.allDay = false AND r.dueAt >= :from) OR (r.allDay = true AND r.dueAt > :dayAgo)')
            ->andWhere('r.completedAt IS NULL')
            ->setParameter('plannings', $planningIds)
            ->setParameter('from', $from)
            ->setParameter('dayAgo', $from->modify('-1 day'))
            ->orderBy('r.dueAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * How many reminders are past due and still not done.
     *
     * The one figure on the dashboard that asks for something rather than
     * reporting it, which is why it is a count and not a list: the number is what
     * makes somebody click, and the list is what they find when they do.
     *
     * @param list<int> $planningIds
     */
    public function countOverdue(array $planningIds, DateTimeImmutable $now): int
    {
        if ([] === $planningIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.planning IN (:plannings)')
            // The rule of `isOverdue()`: a whole-day reminder is late once its
            // day is over, not from its first minute.
            ->andWhere('(r.allDay = false AND r.dueAt < :now) OR (r.allDay = true AND r.dueAt <= :dayAgo)')
            ->andWhere('r.completedAt IS NULL')
            ->setParameter('plannings', $planningIds)
            ->setParameter('now', $now)
            ->setParameter('dayAgo', $now->modify('-1 day'))
            ->getQuery()
            ->getSingleScalarResult();
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
