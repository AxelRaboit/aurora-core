<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<DocumentCategoryInterface> */
class DocumentCategoryRepository extends ResolveTargetEntityRepository
{
    use PaginationTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentCategory::class, DocumentCategoryInterface::class);
    }

    public function findPaginated(int $page, int $limit = 20, ?string $search = null, bool $trashed = false): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.name', Order::Ascending->value);
        $countQb = $this->createQueryBuilder('c')->select('COUNT(c.id)');

        $trashCondition = $trashed ? 'c.deletedAt IS NOT NULL' : 'c.deletedAt IS NULL';
        $qb->andWhere($trashCondition);
        $countQb->andWhere($trashCondition);

        if (null !== $search && '' !== $search) {
            $pattern = '%'.mb_strtolower($search).'%';
            $qb->andWhere('LOWER(c.name) LIKE :search')->setParameter('search', $pattern);
            $countQb->andWhere('LOWER(c.name) LIKE :search')->setParameter('search', $pattern);
        }

        return $this->paginate($qb, $countQb, $page, $limit);
    }

    /** @return DocumentCategory[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.deletedAt IS NULL')
            ->orderBy('c.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every category in the trash since before this moment.
     *
     * @return list<DocumentCategoryInterface>
     */
    public function findTrashedBefore(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.deletedAt IS NOT NULL')
            ->andWhere('c.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    public function countTrashed(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.deletedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * When the oldest row now in the trash was deleted, null when it is empty.
     *
     * Read by the trash overview to say how long is left before the purge
     * takes it. A date rather than a row: the overview shows neither.
     */
    public function oldestTrashedAt(): ?DateTimeImmutable
    {
        $value = $this->createQueryBuilder('c')
            ->select('MIN(c.deletedAt)')
            ->andWhere('c.deletedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : new DateTimeImmutable((string) $value);
    }

    /** @return list<DocumentCategoryInterface> */
    public function findAllTrashed(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.deletedAt IS NOT NULL')
            ->getQuery()
            ->getResult();
    }
}
