<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLinkInterface;
use Aurora\Module\Studio\Sharing\ShareToken;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<DeliverableLinkInterface>
 */
class DeliverableLinkRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliverableLink::class, DeliverableLinkInterface::class);
    }

    public function findByToken(string $token): ?DeliverableLinkInterface
    {
        return $this->findOneBy(['tokenHash' => ShareToken::hash($token)]);
    }

    /**
     * All its addresses, most recent first, revoked ones included: the list
     * also says what was cut off.
     *
     * @return list<DeliverableLinkInterface>
     */
    public function findForDeliverable(DeliverableInterface $deliverable): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.deliverable = :deliverable')
            ->setParameter('deliverable', $deliverable)
            ->orderBy('l.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }
}
