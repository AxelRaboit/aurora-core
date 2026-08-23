<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<PlanningEventInterface> */
class PlanningEventRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningEvent::class, PlanningEventInterface::class);
    }

    /**
     * Everything visible in a window, across the given calendars.
     *
     * **Overlap, not containment.** The condition is `start < windowEnd AND end >
     * windowStart`, which is the only one that catches an event running through
     * the window from before it: a week of holiday that began last month belongs
     * on this month's grid, and a naive `start BETWEEN` drops it. That is the
     * bug every calendar has once.
     *
     * @param list<int> $planningIds
     *
     * @return list<PlanningEventInterface>
     */
    /**
     * The events that are one event, overlapping a window.
     *
     * `rrule IS NULL` covers both a plain event and an occurrence somebody edited
     * into a row of its own: the second is a real event that happens once, and
     * nothing about drawing it differs.
     *
     * The overlap test is `start < to AND end > from`, not "starts inside": an
     * event running from before the window into it is on the screen, and a query
     * comparing only starts is the bug every calendar has once.
     *
     * @param list<int> $planningIds
     *
     * @return list<PlanningEventInterface>
     */
    public function findSinglesInWindow(array $planningIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ([] === $planningIds) {
            return [];
        }

        /** @var list<PlanningEventInterface> $result */
        $result = $this->createQueryBuilder('e')
            ->addSelect('p')
            ->innerJoin('e.planning', 'p')
            ->where('e.planning IN (:plannings)')
            ->andWhere('e.rrule IS NULL')
            ->andWhere('e.startAt < :to')
            ->andWhere('e.endAt > :from')
            ->setParameter('plannings', $planningIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The series that could reach a window, without expanding any of them.
     *
     * This is what `recurrence_until` is for. A series qualifies when it starts
     * before the window ends and has not already finished before the window
     * begins - two column comparisons. Working it out from the rules instead would
     * mean expanding every recurring event in the table on every fetch, for ever.
     *
     * Whether a qualifying series actually lands inside the window is the
     * expander's answer, and it is cheap once the candidates are this few.
     *
     * @param list<int> $planningIds
     *
     * @return list<PlanningEventInterface>
     */
    public function findSeriesReaching(array $planningIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ([] === $planningIds) {
            return [];
        }

        /** @var list<PlanningEventInterface> $result */
        $result = $this->createQueryBuilder('e')
            ->addSelect('p')
            ->innerJoin('e.planning', 'p')
            ->where('e.planning IN (:plannings)')
            ->andWhere('e.rrule IS NOT NULL')
            ->andWhere('e.startAt < :to')
            ->andWhere('e.recurrenceUntil IS NULL OR e.recurrenceUntil > :from')
            ->setParameter('plannings', $planningIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function searchVisible(array $planningIds, string $query, int $limit = 10): array
    {
        $term = mb_trim($query);
        if ([] === $planningIds || '' === $term) {
            return [];
        }

        /** @var list<PlanningEventInterface> $result */
        $result = $this->createQueryBuilder('e')
            ->addSelect('p')
            ->innerJoin('e.planning', 'p')
            ->where('e.planning IN (:plannings)')
            ->andWhere('LOWER(e.title) LIKE :term')
            ->setParameter('plannings', $planningIds)
            ->setParameter('term', '%'.mb_strtolower($term).'%')
            // Most recent first: a search for "recette" usually means the one
            // coming up or the one just gone, not the first ever held.
            ->orderBy('e.startAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findUpcoming(array $planningIds, DateTimeImmutable $from, int $limit = 5): array
    {
        if ([] === $planningIds) {
            return [];
        }

        /** @var list<PlanningEventInterface> $result */
        $result = $this->createQueryBuilder('e')
            ->addSelect('p')
            ->innerJoin('e.planning', 'p')
            ->where('e.planning IN (:plannings)')
            ->andWhere('e.startAt >= :from')
            ->setParameter('plannings', $planningIds)
            ->setParameter('from', $from)
            ->orderBy('e.startAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findBySource(string $sourceType, int $sourceId): ?PlanningEventInterface
    {
        return $this->findOneBy(['sourceType' => $sourceType, 'sourceId' => $sourceId]);
    }
}
