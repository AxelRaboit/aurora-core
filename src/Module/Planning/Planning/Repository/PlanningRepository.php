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
     * The calendars one person may look at: their own, plus the shared ones.
     *
     * Ordered by name and not by id, because this list is a sidebar somebody
     * reads rather than a page of results, and creation order means nothing to
     * them.
     *
     * @return list<PlanningInterface>
     */
    /**
     * Every calendar this person may see.
     *
     * Three ways in: you own it, it is shared with everybody who can reach the
     * module, or somebody shared it with you by name. The third is a left join
     * rather than a second query, so a page load stays one round trip - and
     * `DISTINCT` because a calendar shared with you *and* shared broadly would
     * otherwise arrive twice and appear twice in the sidebar.
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
}
