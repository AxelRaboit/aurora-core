<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every query here is scoped to one person.
 *
 * A folder belongs to its author the way a note does, and there is no screen
 * in which one person's shelf should appear in another's tree. The `$user`
 * argument is therefore not a filter callers may skip.
 *
 * @extends ResolveTargetEntityRepository<NoteFolderInterface>
 */
class NoteFolderRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteFolder::class, NoteFolderInterface::class);
    }

    /**
     * The whole tree of one person, flat.
     *
     * Ordered by position only: the name is encrypted, so the database cannot
     * sort on it and the caller that wants alphabetical order does it in PHP
     * once the rows are hydrated.
     *
     * @return list<NoteFolderInterface>
     */
    public function findAllForUser(CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :user')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('f.position', Order::Ascending->value)
            ->addOrderBy('f.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(CoreUserInterface $user, int $id): ?NoteFolderInterface
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :user')
            ->andWhere('f.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The living folders directly inside this one.
     *
     * @return list<NoteFolderInterface>
     */
    public function findLivingChildrenOf(int $folderId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.parent = :id')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('id', $folderId)
            ->orderBy('f.position', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The folders one person has in the trash, most recently deleted first.
     *
     * Only those trashed on their own: a sub-folder that fell with its parent
     * comes back with it, and listing it separately would offer a restore
     * that puts a folder under a parent still deleted.
     *
     * @return list<NoteFolderInterface>
     */
    public function findTrashedRootsForUser(CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :user')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
            ->setParameter('user', $user)
            ->orderBy('f.deletedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /** @return list<NoteFolderInterface> */
    public function findTrashedWith(int $folderId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.trashedWithFolderId = :id')
            ->setParameter('id', $folderId)
            ->getQuery()
            ->getResult();
    }

    /** @return list<NoteFolderInterface> */
    public function findTrashedBefore(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.deletedAt IS NOT NULL')
            ->andWhere('f.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    public function countTrashedForUser(CoreUserInterface $user): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.user = :user')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function oldestTrashedAtForUser(CoreUserInterface $user): ?DateTimeImmutable
    {
        $value = $this->createQueryBuilder('f')
            ->select('MIN(f.deletedAt)')
            ->where('f.user = :user')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : new DateTimeImmutable((string) $value);
    }

    public function findMaxPositionForUserAndParent(CoreUserInterface $user, ?int $parentId): ?int
    {
        $qb = $this->createQueryBuilder('f')
            ->select('MAX(f.position)')
            ->where('f.user = :user')
            ->setParameter('user', $user);

        if (null === $parentId) {
            $qb->andWhere('f.parent IS NULL');
        } else {
            $qb->andWhere('IDENTITY(f.parent) = :parentId')
                ->setParameter('parentId', $parentId);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return null === $result ? null : (int) $result;
    }

    /**
     * How many living notes sit directly in each of this person's folders.
     *
     * One query for the whole tree rather than one per card: the library
     * shows a count on every folder it draws, and a folder with no note is
     * simply absent from the map.
     *
     * @return array<int, int> folder id => number of notes
     */
    public function countNotesPerFolderForUser(CoreUserInterface $user): array
    {
        /** @var list<array{folderId: int|string|null, total: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(n.folder) AS folderId', 'COUNT(n.id) AS total')
            ->from('Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface', 'n')
            ->where('n.user = :user')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.folder IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('n.folder')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            if (null === $row['folderId']) {
                continue;
            }

            $counts[(int) $row['folderId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * How many living sub-folders sit directly in each of this person's
     * folders.
     *
     * @return array<int, int> folder id => number of sub-folders
     */
    public function countChildrenPerFolderForUser(CoreUserInterface $user): array
    {
        /** @var list<array{parentId: int|string|null, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.parent) AS parentId', 'COUNT(f.id) AS total')
            ->where('f.user = :user')
            ->andWhere('f.deletedAt IS NULL')
            ->andWhere('f.parent IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('f.parent')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            if (null === $row['parentId']) {
                continue;
            }

            $counts[(int) $row['parentId']] = (int) $row['total'];
        }

        return $counts;
    }
}
