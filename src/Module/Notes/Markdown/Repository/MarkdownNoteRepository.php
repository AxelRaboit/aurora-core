<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function sprintf;

/** @extends ResolveTargetEntityRepository<MarkdownNoteInterface> */
class MarkdownNoteRepository extends ResolveTargetEntityRepository
{
    /** Assez pour remplir une vignette de la mosaïque, sans porter la note entière. */
    public const int EXCERPT_LENGTH = 700;

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
     * @return list<array{id: int, title: string|null, tags: list<string>, position: int, createdAt: string, updatedAt: string, favoritedAt: string|null, sharedAt: string|null, coverUrl: string|null, coverPosition: int, appearance: string, version: int, folderId: int|null, spaceId: int}>
     */
    public function findFlatListForUser(CoreUserInterface $user): array
    {
        // `coverUrl`, `coverPosition` et `appearance` voyagent avec la liste
        // pour que l'entête d'une note soit dessinée dès le clic, sans attendre
        // le corps. Sans eux, passer d'une note à bandeau à une autre faisait
        // disparaître l'image puis revenir : cent soixante pixels de saut,
        // mesurés, à chaque changement. Ce sont trois colonnes en clair sur une
        // requête qui en lisait déjà neuf ; seuls le titre et le texte sont
        // chiffrés, donc elles ne coûtent rien à déchiffrer.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->visibleTo($this->createQueryBuilder('n'), 'n', $user)
            ->select('n.id', 'n.title', 'n.tags', 'n.position', 'n.createdAt', 'n.updatedAt', 'n.favoritedAt', 'n.sharedAt', 'n.coverUrl', 'n.coverPosition', 'n.appearance', 'n.version', 'IDENTITY(n.folder) AS folderId', 'IDENTITY(n.space) AS spaceId')
            ->andWhere('n.deletedAt IS NULL')
            ->orderBy('n.position', Order::Ascending->value)
            ->addOrderBy('n.createdAt', Order::Descending->value)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            ...$row,
            'createdAt' => self::asAtom($row['createdAt'] ?? null),
            'updatedAt' => self::asAtom($row['updatedAt'] ?? null),
            'favoritedAt' => self::asAtom($row['favoritedAt'] ?? null),
            // Sans cette ligne, l'écran ne savait jamais qu'une note est
            // ouverte à l'équipe : la marque ne s'affichait pas, le filtre
            // ne trouvait rien, et la bascule de la barre croyait toujours
            // partir d'une note privée - donc disait toujours la même
            // chose. La liste plate ne sert pas le sérialiseur, il faut
            // lui nommer chaque colonne.
            'sharedAt' => self::asAtom($row['sharedAt'] ?? null),
            'spaceId' => (int) $row['spaceId'],
        ], $rows);
    }

    /**
     * Ce qu'une personne peut lire : les notes des espaces qui lui sont
     * ouverts, selon la seule règle de {@see NoteSpaceRepository::readableSubquery()}.
     */
    private function visibleTo(QueryBuilder $qb, string $alias, CoreUserInterface $user): QueryBuilder
    {
        $qb->andWhere(sprintf('IDENTITY(%s.space) IN (%s)', $alias, NoteSpaceRepository::readableSubquery()));

        return NoteSpaceRepository::bindViewer($qb, $user);
    }

    /** Ce qu'une personne peut écrire : les espaces où elle est rédactrice ou plus. */
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

    /** La racine d'un espace. */
    private function rootOf(QueryBuilder $qb, string $alias, NoteSpaceInterface $space): void
    {
        $qb->andWhere(sprintf('%s.space = :rootSpace', $alias))->setParameter('rootSpace', $space);
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
    public function findExcerptsForUser(CoreUserInterface $user, int $length = self::EXCERPT_LENGTH): array
    {
        /** @var list<array{id: int, content: string|null}> $rows */
        $rows = $this->visibleTo($this->createQueryBuilder('n'), 'n', $user)
            ->select('n.id', 'n.content')
            ->andWhere('n.deletedAt IS NULL')
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
     * L'extrait d'une seule note, par la même règle que la liste : ce que la
     * route d'enregistrement renvoie, pour que la carte suive le texte sans
     * attendre un rechargement de la page.
     */
    public function excerptOf(string $content): string
    {
        return $this->summarise($content, self::EXCERPT_LENGTH);
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
        return $this->visibleTo($this->createQueryBuilder('n'), 'n', $user)
            ->andWhere('n.deletedAt IS NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les notes vivantes d'un espace, avec leur texte.
     *
     * Pour ce qui ne doit jamais sortir d'un espace : un lien public qui suit
     * les wiki-liens d'une note, une réécriture de liens après un changement
     * de titre.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findLivingInSpace(NoteSpaceInterface $space): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.space = :space')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('space', $space)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(CoreUserInterface $user, int $id): ?MarkdownNoteInterface
    {
        // Une note qu'on peut écrire, pas une note dont on est l'auteur : c'est
        // l'espace qui décide.
        return $this->writableTo($this->createQueryBuilder('n'), 'n', $user)
            ->andWhere('n.id = :id')
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
        $rows = $this->visibleTo($this->createQueryBuilder('n'), 'n', $user)
            ->select('n.tags')
            ->andWhere('n.deletedAt IS NULL')
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
        return $this->trashOf($this->createQueryBuilder('n'), 'n', $user)
            ->andWhere('n.deletedAt IS NOT NULL')
            ->andWhere('n.trashedWithFolderId IS NULL')
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
    public function findLivingInFolder(NoteSpaceInterface $space, ?int $folderId): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.deletedAt IS NULL')
            ->orderBy('n.position', Order::Ascending->value);

        // Un dossier dit à lui seul où il est ; la racine, elle, n'est à
        // personne : celle d'un carnet, ou celle de l'équipe.
        if (null === $folderId) {
            $this->rootOf($qb, 'n', $space);
            $qb->andWhere('n.folder IS NULL');
        } else {
            $qb->andWhere('IDENTITY(n.folder) = :folderId')
                ->setParameter('folderId', $folderId);
        }

        return $qb->getQuery()->getResult();
    }

    public function countTrashedForUser(CoreUserInterface $user): int
    {
        return (int) $this->trashOf($this->createQueryBuilder('n'), 'n', $user)
            ->select('COUNT(n.id)')
            ->andWhere('n.deletedAt IS NOT NULL')
            ->andWhere('n.trashedWithFolderId IS NULL')
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
        $value = $this->trashOf($this->createQueryBuilder('n'), 'n', $user)
            ->select('MIN(n.deletedAt)')
            ->andWhere('n.deletedAt IS NOT NULL')
            ->andWhere('n.trashedWithFolderId IS NULL')
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

    public function findMaxPositionForUserAndFolder(NoteSpaceInterface $space, ?int $folderId): ?int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('MAX(n.position)');

        if (null === $folderId) {
            $this->rootOf($qb, 'n', $space);
            $qb->andWhere('n.folder IS NULL');
        } else {
            $qb->andWhere('IDENTITY(n.folder) = :folderId')
                ->setParameter('folderId', $folderId);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return null === $result ? null : (int) $result;
    }
}
