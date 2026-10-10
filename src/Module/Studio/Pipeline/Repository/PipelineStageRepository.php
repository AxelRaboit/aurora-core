<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStage;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;
use Aurora\Module\Studio\Pipeline\Enum\PipelineStageRoleEnum;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PipelineStageInterface>
 */
class PipelineStageRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PipelineStage::class, PipelineStageInterface::class);
    }

    /**
     * The pipeline's stages, left to right.
     *
     * @return list<PipelineStageInterface>
     */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.position', Order::Ascending->value)
            ->addOrderBy('s.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    public function findOneByRole(PipelineStageRoleEnum $role): ?PipelineStageInterface
    {
        return $this->findOneBy(['role' => $role]);
    }

    /** The place a new stage takes: after the last one. */
    public function nextPosition(): int
    {
        $highest = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : (int) $highest + 1;
    }

    /** How many customers stand in the way of deleting this stage. */
    public function countCustomers(PipelineStageInterface $stage): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(c.id) FROM '.Customer::class.' c WHERE c.pipelineStage = :stage')
            ->setParameter('stage', $stage)
            ->getSingleScalarResult();
    }
}
