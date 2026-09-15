<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceContentColumnInterface>
 */
class SpaceContentColumnRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceContentColumn::class, SpaceContentColumnInterface::class);
    }

    /**
     * The board's columns, left to right.
     *
     * @return list<SpaceContentColumnInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.space = :space')
            ->setParameter('space', $space)
            ->orderBy('c.position', Order::Ascending->value)
            ->addOrderBy('c.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /** The place a new column takes: after the last one. */
    public function nextPosition(CustomerSpaceInterface $space): int
    {
        $highest = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->where('c.space = :space')
            ->setParameter('space', $space)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : (int) $highest + 1;
    }

    /** How many items stand in the way of deleting this column. */
    public function countItems(SpaceContentColumnInterface $column): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(i.id) FROM '.SpaceContentItem::class.' i WHERE i.column = :column')
            ->setParameter('column', $column)
            ->getSingleScalarResult();
    }
}
