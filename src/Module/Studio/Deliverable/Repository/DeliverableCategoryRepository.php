<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategory;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategoryInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<DeliverableCategoryInterface>
 */
class DeliverableCategoryRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliverableCategory::class, DeliverableCategoryInterface::class);
    }

    /**
     * Dans l'ordre choisi, le nom pour départager.
     *
     * @return list<DeliverableCategoryInterface>
     */
    public function findOrdered(): array
    {
        /** @var list<DeliverableCategoryInterface> $categories */
        $categories = $this->createQueryBuilder('c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $categories;
    }

    public function nextPosition(): int
    {
        /** @var int|null $max */
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }
}
