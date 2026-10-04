<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Beacon\Entity\DeployedInstance;
use Aurora\Module\Beacon\Entity\DeployedInstanceInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<DeployedInstanceInterface> */
class DeployedInstanceRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeployedInstance::class, DeployedInstanceInterface::class);
    }

    public function findOneByInstanceId(string $instanceId): ?DeployedInstanceInterface
    {
        return $this->findOneBy(['instanceId' => $instanceId]);
    }

    /** @return list<DeployedInstanceInterface> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('i')
            ->orderBy('i.lastSeenAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    public function save(DeployedInstanceInterface $instance): void
    {
        $this->getEntityManager()->persist($instance);
        $this->getEntityManager()->flush();
    }

    public function remove(DeployedInstanceInterface $instance): void
    {
        $this->getEntityManager()->remove($instance);
        $this->getEntityManager()->flush();
    }
}
