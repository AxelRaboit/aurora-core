<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
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
    public function findInWindow(array $planningIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ([] === $planningIds) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->addSelect('p')
            // Joined and selected in the same query: a month grid draws the
            // calendar's colour on every chip, and lazy-loading it would be one
            // query per event.
            ->innerJoin('e.planning', 'p')
            ->where('e.planning IN (:plannings)')
            ->andWhere('e.startAt < :to')
            ->andWhere('e.endAt > :from')
            ->setParameter('plannings', $planningIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startAt', Order::Ascending->value)
            ->addOrderBy('e.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The event a module pushed for one of its own records, if it exists.
     *
     * Reads the unique pair the table already indexes, so a module re-announcing
     * the same record updates its event instead of adding a second one.
     */
    /**
     * The next few events starting after a moment.
     *
     * Starting after, not overlapping: a dashboard answers "what is coming",
     * and something already under way is not coming. The grid's window query is
     * the one that needs the overlap test.
     *
     * @param list<int> $planningIds
     *
     * @return list<PlanningEventInterface>
     */
    /**
     * Events whose title matches, among the calendars the reader may see.
     *
     * Scoped, and that is not optional: global search reaches every screen, so an
     * unscoped title match is the shortest path there is to reading somebody
     * else's private calendar.
     *
     * @param list<int> $planningIds
     *
     * @return list<PlanningEventInterface>
     */
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
