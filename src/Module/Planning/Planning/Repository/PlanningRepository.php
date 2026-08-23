<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Order;
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
    public function findVisibleTo(CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.owner = :owner OR p.visibility = :shared')
            ->setParameter('owner', $user)
            ->setParameter('shared', 'shared')
            ->orderBy('p.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }
}
