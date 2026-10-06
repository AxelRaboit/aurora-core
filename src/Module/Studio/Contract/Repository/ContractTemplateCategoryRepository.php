<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateCategory;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateCategoryInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<ContractTemplateCategoryInterface>
 */
class ContractTemplateCategoryRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractTemplateCategory::class, ContractTemplateCategoryInterface::class);
    }

    /**
     * In the order somebody arranged them, name only to break a tie.
     *
     * @return list<ContractTemplateCategoryInterface>
     */
    public function findOrdered(): array
    {
        /** @var list<ContractTemplateCategoryInterface> $categories */
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
