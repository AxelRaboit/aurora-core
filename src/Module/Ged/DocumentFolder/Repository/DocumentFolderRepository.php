<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentFolder\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<DocumentFolderInterface> */
class DocumentFolderRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentFolder::class, DocumentFolderInterface::class);
    }

    /** @return list<DocumentFolderInterface> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.deletedAt IS NULL')
            ->orderBy('f.position', Order::Ascending->value)
            ->addOrderBy('f.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /** @return list<DocumentFolderInterface> */
    public function findRoots(): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.deletedAt IS NULL')
            ->andWhere('f.parent IS NULL')
            ->orderBy('f.position', Order::Ascending->value)
            ->addOrderBy('f.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The folders in the trash, most recently deleted first.
     *
     * Only those trashed on their own: a sub-folder that fell with its parent
     * is not an entry of its own in the trash, it is part of the branch its
     * parent restores. Listing it separately would offer a restore that puts
     * a folder back under a parent that is still deleted.
     *
     * @return list<DocumentFolderInterface>
     */
    public function findTrashedRoots(): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
            ->orderBy('f.deletedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything that fell with this folder, sub-folders included.
     *
     * @return list<DocumentFolderInterface>
     */
    public function findTrashedWith(int $folderId): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.trashedWithFolderId = :id')
            ->setParameter('id', $folderId)
            ->getQuery()
            ->getResult();
    }

    /** @return list<DocumentFolderInterface> */
    public function findChildrenOf(int $folderId, bool $trashed = false): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.parent = :id')
            ->andWhere($trashed ? 'f.deletedAt IS NOT NULL' : 'f.deletedAt IS NULL')
            ->setParameter('id', $folderId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every folder in the trash since before this moment, including those
     * that fell with a parent.
     *
     * Unlike `countTrashed`, the cascade is not excluded here: the purge
     * destroys a branch whole, and a sub-folder left behind would be a row
     * pointing at a parent that no longer exists.
     *
     * @return list<DocumentFolderInterface>
     */
    public function findTrashedBefore(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    public function countTrashed(): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
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
        $value = $this->createQueryBuilder('f')
            ->select('MIN(f.deletedAt)')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : new DateTimeImmutable((string) $value);
    }
}
