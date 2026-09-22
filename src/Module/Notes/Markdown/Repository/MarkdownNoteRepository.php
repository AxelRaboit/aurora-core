<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<MarkdownNoteInterface> */
class MarkdownNoteRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarkdownNote::class, MarkdownNoteInterface::class);
    }

    /**
     * Flat list of all notes for a user, without content (loaded on demand).
     * The library groups them by folder; the browser sorts them, the title
     * being encrypted and therefore beyond the reach of an ORDER BY.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findFlatListForUser(CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('n')
            ->select('n.id', 'n.title', 'n.tags', 'n.position', 'n.createdAt', 'n.updatedAt', 'IDENTITY(n.folder) AS folderId')
            ->where('n.user = :user')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('n.position', Order::Ascending->value)
            ->addOrderBy('n.createdAt', Order::Descending->value)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Full notes (with content) for a user - used by graph/backlinks/unlinked
     * mentions. Loads everything into memory; monitor on large volumes.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findAllWithContentForUser(CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(CoreUserInterface $user, int $id): ?MarkdownNoteInterface
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Histogram of tag → number of the user's notes carrying it.
     * Loads only the `tags` JSON column and aggregates in PHP; the volumes
     * involved (≤ a few hundred notes per user) keep this cheap and
     * portable across DB engines.
     *
     * @return array<string, int>
     */
    public function findTagCountsForUser(CoreUserInterface $user): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('n.tags')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $tags = $row['tags'] ?? [];
            if (!is_array($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                if (!is_string($tag)) {
                    continue;
                }

                $trimmed = mb_trim($tag);
                if ('' === $trimmed) {
                    continue;
                }

                $counts[$trimmed] = ($counts[$trimmed] ?? 0) + 1;
            }
        }

        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);

        return $counts;
    }

    /**
     * The user's trashed notes, most recently deleted first.
     *
     * Only those trashed on their own: a note that fell with its folder is
     * part of the branch that folder restores, not an entry of its own.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findTrashedRootsForUser(CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.deletedAt IS NOT NULL')
            ->andWhere('n.trashedWithFolderId IS NULL')
            ->setParameter('user', $user)
            ->orderBy('n.deletedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The notes that fell with this folder.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findTrashedWithFolder(int $folderId): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.trashedWithFolderId = :id')
            ->setParameter('id', $folderId)
            ->getQuery()
            ->getResult();
    }

    /**
     * The living notes filed in any of these folders.
     *
     * Takes a list rather than one id because the caller that needs it is
     * trashing a branch, and one query for the branch beats one per folder.
     *
     * @param list<int> $folderIds
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findLivingInFolders(array $folderIds): array
    {
        if ([] === $folderIds) {
            return [];
        }

        return $this->createQueryBuilder('n')
            ->where('IDENTITY(n.folder) IN (:ids)')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('ids', $folderIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * The living notes filed directly in this folder, root when null.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findLivingInFolder(CoreUserInterface $user, ?int $folderId): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('n.position', Order::Ascending->value);

        if (null === $folderId) {
            $qb->andWhere('n.folder IS NULL');
        } else {
            $qb->andWhere('IDENTITY(n.folder) = :folderId')
                ->setParameter('folderId', $folderId);
        }

        return $qb->getQuery()->getResult();
    }

    public function countTrashedForUser(CoreUserInterface $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.deletedAt IS NOT NULL')
            ->andWhere('n.trashedWithFolderId IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * When the oldest of this user's trashed notes was deleted, null when
     * their trash is empty.
     *
     * Read by the trash overview to say how long is left before the purge
     * takes it. Per user, like everything about a note: the count on that page
     * is the reader's own, not the installation's.
     */
    public function oldestTrashedAtForUser(CoreUserInterface $user): ?DateTimeImmutable
    {
        $value = $this->createQueryBuilder('n')
            ->select('MIN(n.deletedAt)')
            ->where('n.user = :user')
            ->andWhere('n.deletedAt IS NOT NULL')
            ->andWhere('n.trashedWithFolderId IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : new DateTimeImmutable((string) $value);
    }

    /** @return list<MarkdownNoteInterface> */
    public function findTrashedBefore(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.deletedAt IS NOT NULL')
            ->andWhere('n.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    public function findMaxPositionForUserAndFolder(CoreUserInterface $user, ?int $folderId): ?int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('MAX(n.position)')
            ->where('n.user = :user')
            ->setParameter('user', $user);

        if (null === $folderId) {
            $qb->andWhere('n.folder IS NULL');
        } else {
            $qb->andWhere('IDENTITY(n.folder) = :folderId')
                ->setParameter('folderId', $folderId);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return null === $result ? null : (int) $result;
    }
}
