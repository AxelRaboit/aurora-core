<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Share\Repository;

use Aurora\Module\Planning\Share\Entity\PlanningShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningShare>
 */
class PlanningShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningShare::class);
    }
}
