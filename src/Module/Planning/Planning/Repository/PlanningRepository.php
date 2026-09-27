<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<PlanningInterface> */
class PlanningRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Planning::class, PlanningInterface::class);
    }

    /**
     * Every calendar this person may see.
     *
     * Three ways in: you own it, it is shared with everybody who can reach the
     * module, or somebody shared it with you by name. The third is a left join
     * rather than a second query, so a page load stays one round trip - and
     * `DISTINCT` because a calendar shared with you *and* shared broadly would
     * otherwise arrive twice and appear twice in the sidebar.
     *
     * Ordered by name and not by id: this list is a sidebar somebody reads,
     * and creation order means nothing to them.
     *
     * @return list<PlanningInterface>
     */
    public function findVisibleTo(CoreUserInterface $user): array
    {
        /** @var list<PlanningInterface> $result */
        $result = $this->createQueryBuilder('p')
            ->distinct()
            ->leftJoin('p.shares', 's', Join::WITH, 's.user = :owner')
            ->where('p.owner = :owner OR p.visibility = :shared OR s.id IS NOT NULL')
            ->setParameter('owner', $user)
            ->setParameter('shared', 'shared')
            ->orderBy('p.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Loads everything a feed writes, for these calendars, in three queries.
     *
     * The feed walks each calendar's events, their attendees and each
     * attendee's user, then its reminders - all lazy, so a query per event on
     * a URL every subscribed phone polls every quarter of an hour. Doctrine
     * fills the managed calendars in place, so the writer reads them without
     * asking again. Several queries rather than one: joining events and
     * reminders together would multiply one by the other.
     *
     * @param list<PlanningInterface> $plannings
     */
    public function warmForFeed(array $plannings): void
    {
        if ([] === $plannings) {
            return;
        }

        $this->createQueryBuilder('p')
            ->leftJoin('p.events', 'e')
            ->leftJoin('e.attendees', 'a')
            ->leftJoin('a.user', 'u')
            ->addSelect('e', 'a', 'u')
            ->where('p IN (:plannings)')
            ->setParameter('plannings', $plannings)
            ->getQuery()
            ->getResult();

        // The edited occurrences of each series, which the feed leaves out of
        // the rule. A query of their own: joined above, they would multiply
        // every series by its attendees.
        // Read from the events rather than through `p.events` with a `WITH`:
        // a restricted fetch join would pass for the whole collection.
        $this->getEntityManager()->createQueryBuilder()
            ->select('e', 'o')
            ->from($this->getClassMetadata()->getAssociationTargetClass('events'), 'e')
            ->leftJoin('e.occurrences', 'o')
            ->where('e.planning IN (:plannings)')
            ->andWhere('e.rrule IS NOT NULL')
            ->setParameter('plannings', $plannings)
            ->getQuery()
            ->getResult();

        $this->createQueryBuilder('p')
            ->leftJoin('p.reminders', 'r')
            ->addSelect('r')
            ->where('p IN (:plannings)')
            ->setParameter('plannings', $plannings)
            ->getQuery()
            ->getResult();
    }
}
