<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use DateTimeInterface;
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
     * Des tableaux, pas des entités : la requête sélectionne des colonnes, et
     * l'annotation disait le contraire, ce qui laissait les appelants croire
     * qu'ils tenaient des notes.
     *
     * **Les dates partent en chaînes ISO**, comme celles du sérialiseur.
     * L'hydratation en tableau rend des `DateTimeImmutable`, que `json_encode`
     * écrit `{date, timezone_type, timezone}` : un objet que le navigateur ne
     * sait pas lire comme une date. Personne ne l'avait vu tant que l'écran
     * n'affichait aucune date ; le jour où la bibliothèque a montré « modifiée
     * le », le formatage a levé et la page entière est restée blanche.
     *
     * @return list<array{id: int, title: string|null, tags: list<string>, position: int, createdAt: string, updatedAt: string, favoritedAt: string|null, folderId: int|null}>
     */
    public function findFlatListForUser(CoreUserInterface $user): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('n')
            ->select('n.id', 'n.title', 'n.tags', 'n.position', 'n.createdAt', 'n.updatedAt', 'n.favoritedAt', 'IDENTITY(n.folder) AS folderId')
            ->where('n.user = :user')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('n.position', Order::Ascending->value)
            ->addOrderBy('n.createdAt', Order::Descending->value)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            ...$row,
            'createdAt' => self::asAtom($row['createdAt'] ?? null),
            'updatedAt' => self::asAtom($row['updatedAt'] ?? null),
            'favoritedAt' => self::asAtom($row['favoritedAt'] ?? null),
        ], $rows);
    }

    /** Une date de l'hydratation en tableau, rendue lisible par un navigateur. */
    private static function asAtom(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }

    /**
     * The first words of every note, for the cards that show them.
     *
     * Le corps d'une note est chiffré, donc un extrait se paie en
     * déchiffrement : une requête et 500 notes coûtent huit millisecondes de
     * plus que la liste sans extrait, mesuré sur un jeu de cette taille. Cela
     * reste une requête de plus, appelée seulement par les écrans qui
     * montrent l'extrait.
     *
     * Ce qui sort est du Markdown, que la carte rend en petit : voir un
     * titre, une liste ou une case cochée est ce qui fait reconnaître une
     * note, là où un texte aplati les rendait toutes identiques.
     *
     * @return array<int, string> note id => les premières lignes, en Markdown
     */
    public function findExcerptsForUser(CoreUserInterface $user, int $length = 700): array
    {
        /** @var list<array{id: int, content: string|null}> $rows */
        $rows = $this->createQueryBuilder('n')
            ->select('n.id', 'n.content')
            ->where('n.user = :user')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $excerpts = [];
        foreach ($rows as $row) {
            $excerpt = $this->summarise((string) ($row['content'] ?? ''), $length);

            if ('' !== $excerpt) {
                $excerpts[(int) $row['id']] = $excerpt;
            }
        }

        return $excerpts;
    }

    /**
     * Le début d'une note, tel qu'il se relira.
     *
     * **Du markdown, pas du texte aplati.** La mosaïque montre une vignette
     * de la note, comme Craft : on y reconnaît un titre, une liste, une
     * case cochée d'un coup d'œil, et c'est ce qui répond à « ah oui, c'est
     * celle-là ». Aplati, tout se ressemblait.
     *
     * Deux précautions. Les images partent : une seule en `data:` pèserait
     * plus que tout le reste de la liste, et une vignette n'a pas à la
     * porter. Et une coupe au milieu d'un bloc de code laisserait une
     * clôture manquante, qui ferait passer toute la suite pour du code :
     * on la referme.
     */
    private function summarise(string $content, int $length): string
    {
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $content);
        $text = mb_trim($text);

        if (mb_strlen($text) > $length) {
            $text = mb_substr($text, 0, $length).'…';
        }

        return 1 === mb_substr_count($text, '```') % 2 ? $text."\n```" : $text;
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
     * Les notes que les autres ont partagées une par une.
     *
     * Celles qui sont dans un dossier partagé n'ont pas de date à elles :
     * c'est le dossier qui décide. Cette requête ne rend donc que les
     * notes partagées **seules**, typiquement à la racine.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findSharedByOthers(CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user != :user')
            ->andWhere('n.sharedAt IS NOT NULL')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('n.sharedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les notes vivantes de ces dossiers, quel que soit leur propriétaire.
     *
     * @param list<int> $folderIds
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findLivingInFoldersRegardlessOfOwner(array $folderIds): array
    {
        if ([] === $folderIds) {
            return [];
        }

        return $this->createQueryBuilder('n')
            ->where('IDENTITY(n.folder) IN (:ids)')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('ids', $folderIds)
            ->orderBy('n.position', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Une note par son identifiant, sans regarder à qui elle est.
     *
     * La question du droit de lecture est posée ailleurs, par
     * {@see NoteReadScope} : la mêler à la requête donnerait deux endroits
     * qui décident de la même chose.
     */
    public function findOneLiving(int $id): ?MarkdownNoteInterface
    {
        return $this->createQueryBuilder('n')
            ->where('n.id = :id')
            ->andWhere('n.deletedAt IS NULL')
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
