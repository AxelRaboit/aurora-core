<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Favorite\Entity\NoteFavorite;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function sprintf;

/** @extends ResolveTargetEntityRepository<MarkdownNoteInterface> */
class MarkdownNoteRepository extends ResolveTargetEntityRepository
{
    /** Enough to fill a grid thumbnail, without carrying the whole note. */
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
     * Arrays, not entities: the query selects columns, and the annotation
     * said the opposite, which let callers believe they were holding notes.
     *
     * **Dates go out as ISO strings**, like the serializer's. Array hydration
     * returns `DateTimeImmutable`, which `json_encode` writes as
     * `{date, timezone_type, timezone}`: an object the browser cannot read as
     * a date. Nobody had noticed as long as the screen showed no date; the day
     * the library showed "modifiée le", the formatting threw and the whole
     * page stayed blank.
     *
     * @return list<array{id: int, title: string|null, tags: list<string>, position: int, createdAt: string, updatedAt: string, favoritedAt: string|null, coverUrl: string|null, coverPosition: int, appearance: string, version: int, folderId: int|null, spaceId: int}>
     */
    public function findFlatListForUser(CoreUserInterface $user): array
    {
        // `coverUrl`, `coverPosition` and `appearance` travel with the list
        // so that a note's header is drawn on click, without waiting for the
        // body. Without them, going from one banner note to another made the
        // image disappear then come back: a jump of one hundred and sixty
        // pixels, measured, at each switch. They are three plain-text columns
        // on a query that already read nine; only the title and the text are
        // encrypted, so they cost nothing to decrypt.
        /** @var list<array<string, mixed>> $rows */
        // The favorites of the person asking, joined here: they belong to
        // them, not to the note, and a second query would stick them back
        // row by row.
        $rows = $this->visibleTo($this->createQueryBuilder('n'), 'n', $user)
            ->select('n.id', 'n.title', 'n.tags', 'n.position', 'n.template', 'n.createdAt', 'n.updatedAt', 'fav.createdAt AS favoritedAt', 'n.coverUrl', 'n.coverPosition', 'n.appearance', 'n.version', 'n.craftDocumentId', 'IDENTITY(n.folder) AS folderId', 'IDENTITY(n.space) AS spaceId')
            ->leftJoin(NoteFavorite::class, 'fav', Join::WITH, 'fav.note = n AND fav.user = :favoriteViewer')
            ->setParameter('favoriteViewer', $user)
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
            'spaceId' => (int) $row['spaceId'],
        ], $rows);
    }

    /**
     * What a person can read: the notes of the spaces open to them,
     * according to the single rule of {@see NoteSpaceRepository::readableSubquery()}.
     */
    private function visibleTo(QueryBuilder $queryBuilder, string $alias, CoreUserInterface $user): QueryBuilder
    {
        $queryBuilder->andWhere(sprintf('IDENTITY(%s.space) IN (%s)', $alias, NoteSpaceRepository::readableSubquery()));

        return NoteSpaceRepository::bindViewer($queryBuilder, $user);
    }

    /** What a person can write: the spaces where they are editor or above. */
    private function writableTo(QueryBuilder $queryBuilder, string $alias, CoreUserInterface $user): QueryBuilder
    {
        $queryBuilder->andWhere(sprintf('IDENTITY(%s.space) IN (%s)', $alias, NoteSpaceRepository::writableSubquery()));

        return NoteSpaceRepository::bindViewer($queryBuilder, $user);
    }

    /** The trash a person manages: that of the spaces where they write. */
    private function trashOf(QueryBuilder $queryBuilder, string $alias, CoreUserInterface $user): QueryBuilder
    {
        return $this->writableTo($queryBuilder, $alias, $user);
    }

    /** The root of a space. */
    private function rootOf(QueryBuilder $queryBuilder, string $alias, NoteSpaceInterface $space): void
    {
        $queryBuilder->andWhere(sprintf('%s.space = :rootSpace', $alias))->setParameter('rootSpace', $space);
    }

    /** A date from array hydration, made readable by a browser. */
    private static function asAtom(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }

    /**
     * The first words of every note, for the cards that show them.
     *
     * A note's body is encrypted, so an excerpt is paid for in decryption:
     * one query and 500 notes cost eight milliseconds more than the list
     * without excerpts, measured on a data set of that size. It is still one
     * more query, called only by the screens that show the excerpt.
     *
     * What comes out is Markdown, which the card renders small: seeing a
     * heading, a list or a checked box is what makes a note recognizable,
     * where a flattened text made them all look the same.
     *
     * @return array<int, string> note id => the first lines, in Markdown
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
     * The excerpt of a single note, by the same rule as the list: what the
     * save route returns, so that the card follows the text without waiting
     * for a page reload.
     */
    public function excerptOf(string $content): string
    {
        return $this->summarise($content, self::EXCERPT_LENGTH);
    }

    /**
     * The beginning of a note, as it will be reread.
     *
     * **Markdown, not flattened text.** The grid shows a thumbnail of the
     * note, like Craft: you recognize a heading, a list, a checked box at a
     * glance, and that is what answers "ah yes, that's the one". Flattened,
     * everything looked alike.
     *
     * Two precautions. Images go: a single `data:` one would weigh more than
     * the whole rest of the list, and a thumbnail has no need to carry it.
     * And a cut in the middle of a code block would leave a missing fence,
     * which would make everything after it pass for code: we close it.
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
     * The live notes of a space, with their text.
     *
     * For what must never leave a space: a public link that follows a note's
     * wiki links, a rewrite of links after a title change.
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

    /**
     * The living templates a person can read, in the order of the spaces'
     * trees. The title is encrypted: whoever looks for one by name filters
     * here, in PHP.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findLivingTemplatesForUser(CoreUserInterface $user): array
    {
        return $this->visibleTo($this->createQueryBuilder('n'), 'n', $user)
            ->andWhere('n.template = true')
            ->andWhere('n.deletedAt IS NULL')
            ->orderBy('n.position', Order::Ascending->value)
            ->addOrderBy('n.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The live notes of a space, flat and without their text: enough to draw
     * its tree and its reading order, for someone without an account - the
     * public reading of a space.
     *
     * @return list<array{id: int, title: string|null, position: int, folderId: int|null, spaceId: int}>
     */
    public function findFlatListInSpace(NoteSpaceInterface $space): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('n')
            ->select('n.id', 'n.title', 'n.position', 'IDENTITY(n.folder) AS folderId', 'IDENTITY(n.space) AS spaceId')
            ->where('n.space = :space')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('space', $space)
            ->orderBy('n.position', Order::Ascending->value)
            ->addOrderBy('n.createdAt', Order::Descending->value)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'position' => (int) $row['position'],
            'folderId' => null === $row['folderId'] ? null : (int) $row['folderId'],
            'spaceId' => (int) $row['spaceId'],
        ], $rows);
    }

    /**
     * The live notes of a space, most recently touched first, without their
     * text.
     *
     * For a screen that lists them outside the module - the Notes tab of a
     * client space - and only needs enough to recognize and open them. The
     * text is encrypted: not selecting it is what keeps the list light.
     *
     * @return list<array{id: int, title: ?string, folderId: ?int, updatedAt: ?string, authorName: ?string}>
     */
    public function findListInSpace(NoteSpaceInterface $space): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('n')
            ->select('n.id', 'n.title', 'n.updatedAt', 'IDENTITY(n.folder) AS folderId', 'author.name AS authorName')
            ->leftJoin('n.user', 'author')
            ->where('n.space = :space')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('space', $space)
            ->orderBy('n.updatedAt', Order::Descending->value)
            ->addOrderBy('n.id', Order::Descending->value)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'folderId' => null === $row['folderId'] ? null : (int) $row['folderId'],
            'updatedAt' => self::asAtom($row['updatedAt'] ?? null),
            'authorName' => $row['authorName'],
        ], $rows);
    }

    public function findOneByUserAndId(CoreUserInterface $user, int $id): ?MarkdownNoteInterface
    {
        // A note you can write, not a note you are the author of: the space
        // decides.
        return $this->writableTo($this->createQueryBuilder('n'), 'n', $user)
            ->andWhere('n.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The live notes of these folders, whoever their owner is.
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
     * A note by its id, without looking at whose it is.
     *
     * The question of the read right is asked elsewhere, by
     * {@see NoteSpaceAccess}: mixing it
     * into the query would give two places deciding the same thing.
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
     * The notes of these folders, trash included - for a change of space,
     * which takes everything the branch holds.
     *
     * @param list<int> $folderIds
     *
     * @return list<MarkdownNoteInterface>
     */
    public function findAllInFolders(array $folderIds): array
    {
        if ([] === $folderIds) {
            return [];
        }

        return $this->createQueryBuilder('n')
            ->where('IDENTITY(n.folder) IN (:ids)')
            ->setParameter('ids', $folderIds)
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
        $queryBuilder = $this->createQueryBuilder('n')
            ->where('n.deletedAt IS NULL')
            ->orderBy('n.position', Order::Ascending->value)
            ->addOrderBy('n.id', Order::Ascending->value);

        // A folder says on its own where it is; the root, though, belongs to
        // nobody: a notebook's, or the team's.
        if (null === $folderId) {
            $this->rootOf($queryBuilder, 'n', $space);
            $queryBuilder->andWhere('n.folder IS NULL');
        } else {
            $queryBuilder->andWhere('IDENTITY(n.folder) = :folderId')
                ->setParameter('folderId', $folderId);
        }

        return $queryBuilder->getQuery()->getResult();
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

    /**
     * Pushes every note ranked after `$position` in a folder (or a space's
     * root) down by one, to make room for a copy placed right after its
     * original.
     */
    public function shiftAfter(NoteSpaceInterface $space, ?int $folderId, int $position): void
    {
        $queryBuilder = $this->createQueryBuilder('n')
            ->update()
            ->set('n.position', 'n.position + 1')
            ->where('n.position > :position')
            ->setParameter('position', $position);

        if (null === $folderId) {
            $queryBuilder->andWhere('n.space = :space')->andWhere('n.folder IS NULL')->setParameter('space', $space);
        } else {
            $queryBuilder->andWhere('IDENTITY(n.folder) = :folderId')->setParameter('folderId', $folderId);
        }

        $queryBuilder->getQuery()->execute();
    }

    public function findMaxPositionForUserAndFolder(NoteSpaceInterface $space, ?int $folderId): ?int
    {
        $queryBuilder = $this->createQueryBuilder('n')
            ->select('MAX(n.position)');

        if (null === $folderId) {
            $this->rootOf($queryBuilder, 'n', $space);
            $queryBuilder->andWhere('n.folder IS NULL');
        } else {
            $queryBuilder->andWhere('IDENTITY(n.folder) = :folderId')
                ->setParameter('folderId', $folderId);
        }

        $result = $queryBuilder->getQuery()->getSingleScalarResult();

        return null === $result ? null : (int) $result;
    }
}
