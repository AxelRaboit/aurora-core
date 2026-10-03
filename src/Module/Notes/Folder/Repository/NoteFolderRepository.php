<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function sprintf;

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
        return $this->visibleTo($this->createQueryBuilder('f'), 'f', $user)
            ->andWhere('f.deletedAt IS NULL')
            ->orderBy('f.position', Order::Ascending->value)
            ->addOrderBy('f.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les dossiers vivants d'un espace, dans l'ordre du panneau.
     *
     * @return list<NoteFolderInterface>
     */
    public function findLivingInSpace(NoteSpaceInterface $space): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.space = :space')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('space', $space)
            ->orderBy('f.position', Order::Ascending->value)
            ->addOrderBy('f.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(CoreUserInterface $user, int $id): ?NoteFolderInterface
    {
        // Un dossier qu'on peut écrire : c'est l'espace qui décide.
        return $this->writableTo($this->createQueryBuilder('f'), 'f', $user)
            ->andWhere('f.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Les dossiers d'une personne, quel que soit leur propriétaire.
     *
     * @param list<int> $ids
     *
     * @return list<NoteFolderInterface>
     */
    public function findLivingByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->where('f.id IN (:ids)')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * The living folders directly inside this one.
     *
     * @return list<NoteFolderInterface>
     */
    /**
     * Les enfants de ces dossiers, corbeille comprise : ce qui change
     * d'espace avec sa branche doit emporter aussi ce qui dort à la
     * corbeille, sinon sa restauration le rendrait dans un espace qui n'est
     * plus celui de son dossier.
     *
     * @param list<int> $folderIds
     *
     * @return list<NoteFolderInterface>
     */
    public function findAllChildrenOfAny(array $folderIds): array
    {
        if ([] === $folderIds) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->where('IDENTITY(f.parent) IN (:ids)')
            ->setParameter('ids', $folderIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * The living children of several folders at once, their owner with them.
     *
     * For walking a tree a level at a time: one query per depth, where asking
     * folder by folder cost one per node, leaves included.
     *
     * @param list<int> $folderIds
     *
     * @return list<NoteFolderInterface>
     */
    public function findLivingChildrenOfAny(array $folderIds): array
    {
        if ([] === $folderIds) {
            return [];
        }

        // Jointure externe : l'auteur d'un dossier peut avoir quitté
        // l'instance, et son dossier reste dans la branche.
        return $this->createQueryBuilder('f')
            ->leftJoin('f.user', 'u')
            ->addSelect('u')
            ->where('IDENTITY(f.parent) IN (:ids)')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('ids', $folderIds)
            ->orderBy('f.position', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

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
        // The parent comes along: the trash names it beside each folder.
        return $this->trashOf($this->createQueryBuilder('f'), 'f', $user)
            ->leftJoin('f.parent', 'p')
            ->addSelect('p')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
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
        return (int) $this->trashOf($this->createQueryBuilder('f'), 'f', $user)
            ->select('COUNT(f.id)')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function oldestTrashedAtForUser(CoreUserInterface $user): ?DateTimeImmutable
    {
        $value = $this->trashOf($this->createQueryBuilder('f'), 'f', $user)
            ->select('MIN(f.deletedAt)')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : new DateTimeImmutable((string) $value);
    }

    /** Pushes every folder ranked after `$position` under a parent (or at a space's root) down by one. */
    public function shiftAfter(NoteSpaceInterface $space, ?int $parentId, int $position): void
    {
        $qb = $this->createQueryBuilder('f')
            ->update()
            ->set('f.position', 'f.position + 1')
            ->where('f.position > :position')
            ->setParameter('position', $position);

        if (null === $parentId) {
            $qb->andWhere('f.space = :space')->andWhere('f.parent IS NULL')->setParameter('space', $space);
        } else {
            $qb->andWhere('IDENTITY(f.parent) = :parentId')->setParameter('parentId', $parentId);
        }

        $qb->getQuery()->execute();
    }

    public function findMaxPositionForUserAndParent(NoteSpaceInterface $space, ?int $parentId): ?int
    {
        $qb = $this->createQueryBuilder('f')
            ->select('MAX(f.position)');

        if (null === $parentId) {
            $qb->andWhere('f.space = :rootSpace')->setParameter('rootSpace', $space);
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
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(n.folder) AS folderId', 'COUNT(n.id) AS total')
            ->from(MarkdownNoteInterface::class, 'n')
            ->andWhere(sprintf('IDENTITY(n.space) IN (%s)', NoteSpaceRepository::readableSubquery()))
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.folder IS NOT NULL')
            ->groupBy('n.folder');

        /** @var list<array{folderId: int|string|null, total: int|string}> $rows */
        $rows = NoteSpaceRepository::bindViewer($qb, $user)
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
        $rows = $this->visibleTo($this->createQueryBuilder('f'), 'f', $user)
            ->select('IDENTITY(f.parent) AS parentId', 'COUNT(f.id) AS total')
            ->andWhere('f.deletedAt IS NULL')
            ->andWhere('f.parent IS NOT NULL')
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

    /** Les dossiers des espaces qu'une personne peut lire. */
    private function visibleTo(QueryBuilder $qb, string $alias, CoreUserInterface $user): QueryBuilder
    {
        $qb->andWhere(sprintf('IDENTITY(%s.space) IN (%s)', $alias, NoteSpaceRepository::readableSubquery()));

        return NoteSpaceRepository::bindViewer($qb, $user);
    }

    /** Les dossiers des espaces où une personne écrit. */
    private function writableTo(QueryBuilder $qb, string $alias, CoreUserInterface $user): QueryBuilder
    {
        $qb->andWhere(sprintf('IDENTITY(%s.space) IN (%s)', $alias, NoteSpaceRepository::writableSubquery()));

        return NoteSpaceRepository::bindViewer($qb, $user);
    }

    /** La corbeille qu'une personne gère : celle des espaces où elle écrit. */
    private function trashOf(QueryBuilder $qb, string $alias, CoreUserInterface $user): QueryBuilder
    {
        return $this->writableTo($qb, $alias, $user);
    }
}
