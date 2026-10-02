<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\SpaceDeliverable\Entity\SpaceDeliverableInterface;
use Aurora\Module\Studio\SpaceDeliverable\Entity\SpaceDeliverableLink;
use Aurora\Module\Studio\SpaceDeliverable\Entity\SpaceDeliverableLinkInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceDeliverableLinkInterface>
 */
class SpaceDeliverableLinkRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceDeliverableLink::class, SpaceDeliverableLinkInterface::class);
    }

    public function findByToken(string $token): ?SpaceDeliverableLinkInterface
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Toutes ses adresses, la plus récente d'abord, révoquées comprises : la
     * liste dit aussi ce qui a été coupé.
     *
     * @return list<SpaceDeliverableLinkInterface>
     */
    public function findForDeliverable(SpaceDeliverableInterface $deliverable): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.deliverable = :deliverable')
            ->setParameter('deliverable', $deliverable)
            ->orderBy('l.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }
}
