<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Post\Entity\GridSection;
use Aurora\Module\Editorial\Post\Entity\GridSectionInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<GridSectionInterface>
 */
class GridSectionRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GridSection::class, GridSectionInterface::class);
    }

    /**
     * One person's sections, by name.
     *
     * @return list<GridSectionInterface>
     */
    public function findOwnedBy(CoreUserInterface $owner): array
    {
        /** @var list<GridSectionInterface> $sections */
        $sections = $this->createQueryBuilder('s')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $sections;
    }
}
