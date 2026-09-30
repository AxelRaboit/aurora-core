<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Space\NoteSpaceEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\QueryBuilder;
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
        return $this->visibleTo($this->createQueryBuilder('f'), 'f', $user)
            ->andWhere('f.deletedAt IS NULL')
            ->orderBy('f.position', Order::Ascending->value)
            ->addOrderBy('f.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(CoreUserInterface $user, int $id): ?NoteFolderInterface
    {
        // Le carnet personnel seulement ; un dossier d'équipe passe par
        // NoteSpaceAccess, qui regarde le droit et non l'auteur.
        return $this->personalOf($this->createQueryBuilder('f'), 'f', $user)
            ->andWhere('f.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Les dossiers que les autres ont partagés.
     *
     * Ce sont les **racines** du partage : un dossier partagé entraîne ce
     * qu'il contient, donc un sous-dossier d'un dossier déjà partagé n'a
     * pas à porter sa propre date, et n'apparaît pas ici. Les siens sont
     * exclus - on ne se voit pas partager avec soi-même.
     *
     * @return list<NoteFolderInterface>
     */
    public function findSharedByOthers(CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.user != :user')
            ->andWhere('f.sharedAt IS NOT NULL')
            ->andWhere('f.deletedAt IS NULL')
            ->andWhere('f.space = :personalSpace')
            ->setParameter('user', $user)
            ->setParameter('personalSpace', NoteSpaceEnum::Personal)
            ->orderBy('f.sharedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les dossiers partagés, les siens compris.
     *
     * Sert à calculer une portée de lecture : pour savoir si une note est
     * lisible, il faut connaître toute la chaîne de ses parents, et un
     * dossier partagé peut appartenir à n'importe qui.
     *
     * @return list<NoteFolderInterface>
     */
    public function findAllShared(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.sharedAt IS NOT NULL')
            ->andWhere('f.deletedAt IS NULL')
            ->getQuery()
            ->getResult();
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

        return $this->createQueryBuilder('f')
            ->innerJoin('f.user', 'u')
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
    public function findTrashedRootsForUser(CoreUserInterface $user, bool $withTeam = false): array
    {
        // The parent comes along: the trash names it beside each folder.
        return $this->trashOf($this->createQueryBuilder('f'), 'f', $user, $withTeam)
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

    public function countTrashedForUser(CoreUserInterface $user, bool $withTeam = false): int
    {
        return (int) $this->trashOf($this->createQueryBuilder('f'), 'f', $user, $withTeam)
            ->select('COUNT(f.id)')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function oldestTrashedAtForUser(CoreUserInterface $user, bool $withTeam = false): ?DateTimeImmutable
    {
        $value = $this->trashOf($this->createQueryBuilder('f'), 'f', $user, $withTeam)
            ->select('MIN(f.deletedAt)')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->andWhere('f.trashedWithFolderId IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : new DateTimeImmutable((string) $value);
    }

    public function findMaxPositionForUserAndParent(CoreUserInterface $user, ?int $parentId, NoteSpaceEnum $space = NoteSpaceEnum::Personal): ?int
    {
        $qb = $this->createQueryBuilder('f')
            ->select('MAX(f.position)');

        if (null === $parentId) {
            // La racine d'un espace : le carnet de quelqu'un, ou l'équipe.
            NoteSpaceEnum::Team === $space
                ? $qb->andWhere('f.space = :teamSpace')->setParameter('teamSpace', NoteSpaceEnum::Team)
                : $this->personalOf($qb, 'f', $user);
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
            ->from(MarkdownNoteInterface::class, 'n')
            ->andWhere('(n.user = :visibleUser AND n.space = :personalSpace) OR n.space = :teamSpace')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.folder IS NOT NULL')
            ->setParameter('visibleUser', $user)
            ->setParameter('personalSpace', NoteSpaceEnum::Personal)
            ->setParameter('teamSpace', NoteSpaceEnum::Team)
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

    /** Ce qu'une personne voit : ses dossiers, et ceux de l'équipe. */
    private function visibleTo(QueryBuilder $qb, string $alias, CoreUserInterface $user): QueryBuilder
    {
        return $qb
            ->andWhere(sprintf('(%1$s.user = :visibleUser AND %1$s.space = :personalSpace) OR %1$s.space = :teamSpace', $alias))
            ->setParameter('visibleUser', $user)
            ->setParameter('personalSpace', NoteSpaceEnum::Personal)
            ->setParameter('teamSpace', NoteSpaceEnum::Team);
    }

    private function personalOf(QueryBuilder $qb, string $alias, CoreUserInterface $user): QueryBuilder
    {
        return $qb
            ->andWhere(sprintf('%1$s.user = :ownerUser AND %1$s.space = :personalSpace', $alias))
            ->setParameter('ownerUser', $user)
            ->setParameter('personalSpace', NoteSpaceEnum::Personal);
    }

    /** Sa corbeille, plus celle de l'équipe quand on a le droit d'y écrire. */
    private function trashOf(QueryBuilder $qb, string $alias, CoreUserInterface $user, bool $withTeam): QueryBuilder
    {
        return $withTeam ? $this->visibleTo($qb, $alias, $user) : $this->personalOf($qb, $alias, $user);
    }
}
