<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Service\ImageRenditionGenerator;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Search\DocumentOrientationEnum;
use Aurora\Module\Ged\Document\Search\DocumentSearchFieldEnum;
use Aurora\Module\Ged\Document\Search\DocumentSearchFilters;
use Aurora\Module\Ged\Document\Search\DocumentWeightEnum;
use Aurora\Module\Ged\Document\Service\DocumentRelocator;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/** @extends ResolveTargetEntityRepository<DocumentInterface> */
class DocumentRepository extends ResolveTargetEntityRepository implements ResetInterface
{
    use PaginationTrait;

    /**
     * What a stored path resolved to, for the rest of the request.
     *
     * Serving one file asks twice about the same row: the access guard for its
     * status, the locator for its disk. Kept for one request and one worker
     * message, see {@see self::reset()}.
     *
     * @var array<string, array{status: DocumentStatusEnum, storageDisk: StorageDiskEnum}|null>
     */
    private array $pathRows = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class, DocumentInterface::class);
    }

    public function findPaginated(
        int $page,
        int $limit = 20,
        ?string $search = null,
        ?int $categoryId = null,
        ?int $tagId = null,
        ?int $folderId = null,
        ?DocumentStatusEnum $status = null,
        ?MimeGroupEnum $mimeGroup = null,
        bool $rootOnly = false,
        ?StorageDiskEnum $storageDisk = null,
        bool $trashed = false,
        bool $originalsOnly = false,
        string $sort = 'date',
        string $direction = 'desc',
        DocumentSearchFilters $filters = new DocumentSearchFilters(),
    ): array {
        // The original rides along: an alternate names it on its row, and
        // reading it lazily would cost one query per alternate on the page.
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.category', 'c')
            ->leftJoin('d.folder', 'folder')
            ->leftJoin('d.original', 'original')
            ->addSelect('c', 'folder', 'original');
        $this->orderByFamily($qb, $trashed, $sort, $direction);
        $countQb = $this->createQueryBuilder('d')->select('COUNT(d.id)');

        // The trash is the same listing with the condition flipped, not a
        // second finder: every filter above keeps working inside it, and a
        // document can only ever be on one side of this line.
        $trashCondition = $trashed ? 'd.deletedAt IS NOT NULL' : 'd.deletedAt IS NULL';
        $qb->andWhere($trashCondition);
        $countQb->andWhere($trashCondition);

        if (null !== $search && '' !== $search) {
            $pattern = '%'.mb_strtolower($search).'%';
            $match = $this->searchMatch($filters->searchIn, $originalsOnly);

            $qb->andWhere($match)->setParameter('search', $pattern);
            $countQb->andWhere($match)->setParameter('search', $pattern);
        }

        $this->applySearchFilters($qb, $filters);
        $this->applySearchFilters($countQb, $filters);

        if (null !== $categoryId) {
            $qb->andWhere('d.category = :cat')->setParameter('cat', $categoryId);
            $countQb->andWhere('d.category = :cat')->setParameter('cat', $categoryId);
        }

        if (null !== $tagId) {
            $qb->innerJoin('d.tags', 'tagFilter')->andWhere('tagFilter.id = :tagId')->setParameter('tagId', $tagId);
            $countQb->innerJoin('d.tags', 'tagFilter')->andWhere('tagFilter.id = :tagId')->setParameter('tagId', $tagId);
        }

        if (null !== $folderId) {
            $qb->andWhere('d.folder = :folder')->setParameter('folder', $folderId);
            $countQb->andWhere('d.folder = :folder')->setParameter('folder', $folderId);
        } elseif ($rootOnly) {
            // Sidebar "Root" navigation: only docs without a folder, mirroring
            // Media's root-folder view. When neither folderId nor rootOnly is
            // set, the listing falls back to cross-folder (existing behavior).
            $qb->andWhere('d.folder IS NULL');
            $countQb->andWhere('d.folder IS NULL');
        }

        if ($status instanceof DocumentStatusEnum) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
            $countQb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        if ($mimeGroup instanceof MimeGroupEnum) {
            $mimeGroup->applyTo($qb, 'd');
            $mimeGroup->applyTo($countQb, 'd');
        }

        if ($storageDisk instanceof StorageDiskEnum) {
            $qb->andWhere('d.storageDisk = :storageDisk')->setParameter('storageDisk', $storageDisk);
            $countQb->andWhere('d.storageDisk = :storageDisk')->setParameter('storageDisk', $storageDisk);
        }

        // A family shown as its original alone: the alternates are one click
        // away on it, and the listing stops showing three times one visual.
        // An alternate whose original is in the trash stands on its own
        // until the original comes back: hidden here, it would be shown
        // nowhere at all.
        if ($originalsOnly) {
            $qb->andWhere('d.original IS NULL OR original.deletedAt IS NOT NULL');
            $countQb->leftJoin('d.original', 'original')->andWhere('d.original IS NULL OR original.deletedAt IS NOT NULL');
        }

        $result = $this->paginate($qb, $countQb, $page, $limit);
        $this->hydrateDocumentTags($result['items']);

        return $result;
    }

    /**
     * Cheap LIKE-based search over `title` + `original_name`, capped at
     * `$limit` rows. Powers the global suite search controller's
     * "Documents" pane (formerly served by the Media library).
     *
     * @return list<Document>
     */
    /**
     * How many documents currently live on a given suite.
     *
     * Asked before letting an administrator disconnect a remote storage: the
     * credentials are the only way back to those bytes, and forgetting them
     * while rows still point there turns every one of those documents into a
     * broken link, with nothing left to say which.
     */
    public function countOnDisk(StorageDiskEnum $disk): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.storageDisk = :disk')
            ->setParameter('disk', $disk)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The same count, of the documents that are still in the médiathèque.
     *
     * The plain one deliberately counts the trash too, because it guards
     * disconnecting a backend and bytes in the trash are still bytes only
     * those credentials can reach. "Move everything across" is the other
     * question: what it reports on is what the reader can see in the listing.
     */
    public function countLivingOnDisk(StorageDiskEnum $disk): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.storageDisk = :disk')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('disk', $disk)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Everything that would have to move for one backend to hold it all.
     *
     * Ids rather than entities: the caller hands each one to a worker, which
     * loads the document itself when it gets there, so hydrating hundreds of
     * them here would be building objects to read one column off each.
     *
     * **The trash stays where it is.** Its files are on their way out - the
     * retention sweep deletes the bytes - and copying them to a metered bucket
     * on the way would be paying to store what is about to be thrown away.
     *
     * A document already mid-move is not excluded here. The state is claimed
     * inside {@see DocumentRelocator}, under
     * a condition, and re-reading it now would only widen the window between
     * the question and the answer: the relocator turns the second attempt away
     * as busy, which is the same outcome with no race in it.
     *
     * @return list<int>
     */
    public function idsNotOnDisk(StorageDiskEnum $disk): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.id')
            ->where('d.storageDisk != :disk')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('disk', $disk)
            ->orderBy('d.id', Order::Ascending->value)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Same question, asked of one suite only.
     *
     * Relocation needs it because the paths do not change when a document
     * moves: only the disk does. Asking the plain question after a move would
     * answer "still in use" for every path, since the rows now point at the
     * same paths on the other side, and nothing would ever be freed at the
     * source.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public function filterPathsInUseOnDisk(array $paths, StorageDiskEnum $disk): array
    {
        if ([] === $paths) {
            return [];
        }

        /** @var list<array{filePath: string|null, thumbnailPath: string|null}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.filePath', 'd.thumbnailPath')
            ->where('d.filePath IN (:paths) OR d.thumbnailPath IN (:paths)')
            ->andWhere('d.storageDisk = :disk')
            ->setParameter('paths', $paths)
            ->setParameter('disk', $disk)
            ->getQuery()
            ->getResult();

        $inUse = [];
        foreach ($rows as $row) {
            foreach ([$row['filePath'], $row['thumbnailPath']] as $path) {
                if (null !== $path && in_array($path, $paths, true)) {
                    $inUse[$path] = true;
                }
            }
        }

        return array_keys($inUse);
    }

    /**
     * Of the given relative paths, the ones still pointed at by a surviving
     * document row - through either `filePath` or `thumbnailPath`.
     *
     * Deleting a document has to erase its bytes, but a path can legitimately
     * be shared: a version row snapshots the live document's own `filePath`,
     * and nothing stops two rows from being pointed at the same file. Call
     * this *after* the rows are gone; whatever comes back is still owed to
     * someone and must survive.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public function filterPathsInUse(array $paths): array
    {
        if ([] === $paths) {
            return [];
        }

        /** @var list<array{filePath: string|null, thumbnailPath: string|null}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.filePath', 'd.thumbnailPath')
            ->where('d.filePath IN (:paths) OR d.thumbnailPath IN (:paths)')
            ->setParameter('paths', $paths)
            ->getQuery()
            ->getResult();

        $inUse = [];
        foreach ($rows as $row) {
            foreach ([$row['filePath'], $row['thumbnailPath']] as $path) {
                if (null !== $path && in_array($path, $paths, true)) {
                    $inUse[$path] = true;
                }
            }
        }

        return array_keys($inUse);
    }

    /**
     * The status of the living document that owns `$path`, or null when no
     * living document does.
     *
     * Asked by the serving endpoint, which receives a key and nothing else,
     * to decide whether a visitor with no session may read it. Three kinds of
     * key reach it and all three must resolve, because a picture that is
     * withheld while its `medium` rendition is not has been published by
     * accident:
     *
     *  - the document's own file, matched on `filePath`;
     *  - its rendered still (a PDF's first page, a film's poster), matched on
     *    `thumbnailPath`, which is a column like the other;
     *  - one of its responsive renditions, which live in a JSON column and so
     *    are matched by shape instead - see {@see renditionSourcePattern()}.
     *
     * Null for a key no row claims, which covers an orphan file left behind
     * by a deletion and the snapshot of a previous version: neither is a
     * document anybody may read without being asked who they are.
     *
     * Trashed documents answer null too. A document in the bin is withdrawn,
     * whatever its status column still says.
     */
    public function findStatusForPath(string $path): ?DocumentStatusEnum
    {
        return $this->pathRow($path)['status'] ?? null;
    }

    /**
     * The disk holding a file of the library - its source or one of its
     * renditions - or null when no live document owns the path. Lets the file
     * locator skip asking a remote disk whether the object exists.
     */
    public function findStorageDiskForPath(string $path): ?StorageDiskEnum
    {
        return $this->pathRow($path)['storageDisk'] ?? null;
    }

    /** Forgets the paths resolved so far, between two messages of a worker. */
    public function reset(): void
    {
        $this->pathRows = [];
    }

    /** @return array{status: DocumentStatusEnum, storageDisk: StorageDiskEnum}|null */
    private function pathRow(string $path): ?array
    {
        if (!array_key_exists($path, $this->pathRows)) {
            /** @var array{status: DocumentStatusEnum, storageDisk: StorageDiskEnum}|null $row */
            $row = $this->pathQuery($path, 'd.status, d.storageDisk')->getQuery()->getOneOrNullResult();
            $this->pathRows[$path] = $row;
        }

        return $this->pathRows[$path];
    }

    /**
     * The one document a stored path belongs to, selecting only what is asked.
     */
    private function pathQuery(string $path, string $select): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->select($select)
            ->andWhere('d.deletedAt IS NULL')
            ->setMaxResults(1);

        $renditionPattern = $this->renditionSourcePattern($path);

        if (null === $renditionPattern) {
            $queryBuilder
                ->andWhere('d.filePath = :path OR d.thumbnailPath = :path')
                ->setParameter('path', $path);
        } else {
            // A rendition carries its source's basename but not its extension
            // (everything is re-encoded to WebP), so the source is matched on
            // its stem. `ESCAPE` is set because a stem is slugged and could
            // in principle be made to carry a wildcard.
            $queryBuilder
                ->andWhere("d.filePath LIKE :pattern ESCAPE '!'")
                ->setParameter('pattern', $renditionPattern);
        }

        return $queryBuilder;
    }

    /**
     * `ged/2026/05/variants/medium/photo-a1b2.webp` → `ged/2026/05/photo-a1b2.%`,
     * and null for any key that is not shaped like a rendition.
     *
     * Mirrors the key {@see ImageRenditionGenerator}
     * writes, which is the source's own directory plus `variants/<size>/`.
     * The coupling is real and is pinned by a test: change how a rendition is
     * named and this stops finding its owner, which fails open onto the
     * privilege check rather than onto the public.
     */
    private function renditionSourcePattern(string $path): ?string
    {
        if (1 !== preg_match('#^(?P<dir>.+)/variants/[^/]+/(?P<stem>[^/]+)\.[^/.]+$#', $path, $matches)) {
            return null;
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $matches['dir'].'/'.$matches['stem']);

        return $escaped.'.%';
    }

    public function searchByName(string $query, int $limit = 10): array
    {
        $pattern = '%'.mb_strtolower($query).'%';

        return $this->createQueryBuilder('d')
            ->where('LOWER(d.title) LIKE :pattern OR LOWER(d.originalName) LIKE :pattern')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('pattern', $pattern)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Total bytes consumed by Documents on disk (sum of `size`). Used by
     * the dashboard storage tile and any quota / cleanup tooling. Returns 0
     * when no document has a non-null size (empty library).
     */
    public function getTotalStorageSize(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.size), 0)')
            ->where('d.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, int> map of mime_type => document count
     */
    public function countGroupedByMimeType(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.mimeType AS mimeType, COUNT(d.id) AS cnt')
            ->where('d.deletedAt IS NULL')
            ->groupBy('d.mimeType')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'cnt', 'mimeType');
    }

    /**
     * @return array<int, int> map of folder_id => document count
     */
    public function countGroupedByFolders(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.folder) AS folderId, COUNT(d.id) AS cnt')
            ->where('d.folder IS NOT NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->groupBy('d.folder')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['folderId']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * How many documents are sitting in the trash.
     *
     * Read for the badge on the listing's trash filter, so the answer has to
     * be a count rather than a page: the point is to say that something is in
     * there without loading it.
     */
    public function countTrashed(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.deletedAt IS NOT NULL')
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
        $value = $this->createQueryBuilder('d')
            ->select('MIN(d.deletedAt)')
            ->andWhere('d.deletedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : new DateTimeImmutable((string) $value);
    }

    /**
     * The documents still in the library inside these folders.
     *
     * Used when a folder is deleted: what is already in the trash keeps the
     * reason it got there, and must not be re-stamped as having fallen with
     * the folder - otherwise restoring the folder would bring it back too.
     *
     * @param list<int> $folderIds
     *
     * @return list<Document>
     */
    public function findLivingIn(array $folderIds): array
    {
        if ([] === $folderIds) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->where('d.folder IN (:ids)')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('ids', $folderIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * The documents that fell with this folder.
     *
     * Only those still in the trash: a document restored on its own since is
     * back in the library, and must not be moved or trashed again because a
     * stale marker still names the folder.
     *
     * @return list<Document>
     */
    public function findTrashedWith(int $folderId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.trashedWithFolderId = :id')
            ->andWhere('d.deletedAt IS NOT NULL')
            ->setParameter('id', $folderId)
            ->getQuery()
            ->getResult();
    }

    /**
     * The living alternates of the living documents in these folders, filed
     * somewhere else - at the root, or in a folder outside the list.
     *
     * What a folder sent to the trash "with the families" has to take along
     * beyond its own contents. The alternates filed inside the folders are
     * already part of them, so they are left out.
     *
     * @param list<int> $folderIds
     *
     * @return list<Document>
     */
    public function findLivingAlternatesFiledOutside(array $folderIds): array
    {
        if ([] === $folderIds) {
            return [];
        }

        return $this->livingAlternatesFiledOutside($folderIds)
            ->select('d')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many documents `findLivingAlternatesFiledOutside()` would return,
     * for the confirmation that offers to take them along.
     *
     * @param list<int> $folderIds
     */
    public function countLivingAlternatesFiledOutside(array $folderIds): int
    {
        if ([] === $folderIds) {
            return 0;
        }

        return (int) $this->livingAlternatesFiledOutside($folderIds)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param list<int> $folderIds */
    private function livingAlternatesFiledOutside(array $folderIds): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->innerJoin('d.original', 'o')
            ->where('o.folder IN (:ids)')
            ->andWhere('o.deletedAt IS NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.folder IS NULL OR d.folder NOT IN (:ids)')
            ->setParameter('ids', $folderIds);
    }

    /** The sort keys the listing accepts, as the screen names them. */
    public const array SORTS = ['date', 'name', 'size'];

    /**
     * The search box's condition, for the fields it was asked to read.
     *
     * Everything is a `LIKE` on lowered text, as the title search always was:
     * a library of a few thousand rows does not need an index to answer, and
     * a word typed in the box should find it wherever it sits in a name.
     * Tags, category and folder go through subqueries so the count query,
     * which joins nothing, asks exactly the same question.
     */
    private function searchMatch(DocumentSearchFieldEnum $field, bool $originalsOnly): string
    {
        $titles = ['LOWER(d.title) LIKE :search', 'LOWER(d.reference) LIKE :search', 'LOWER(d.alternateLabel) LIKE :search'];

        // Folded, an alternate is not a row of its own: a search that only
        // it answers would come back empty. Its original answers for it,
        // and the row says which member matched.
        if ($originalsOnly) {
            $titles[] = sprintf(
                'EXISTS (SELECT 1 FROM %s searched WHERE searched.original = d AND searched.deletedAt IS NULL AND (LOWER(searched.title) LIKE :search OR LOWER(searched.alternateLabel) LIKE :search))',
                $this->getEntityName(),
            );
        }

        $files = ['LOWER(d.originalName) LIKE :search', 'LOWER(d.fileName) LIKE :search'];
        $texts = [
            'LOWER(d.description) LIKE :search',
            'LOWER(d.alt) LIKE :search',
            'LOWER(d.caption) LIKE :search',
            'LOWER(d.attributionName) LIKE :search',
        ];
        $metadata = $this->getClassMetadata();
        $classification = [
            sprintf('EXISTS (SELECT 1 FROM %s searchedTag WHERE searchedTag MEMBER OF d.tags AND LOWER(searchedTag.name) LIKE :search)', $metadata->getAssociationTargetClass('tags')),
            sprintf('d.category IN (SELECT searchedCategory.id FROM %s searchedCategory WHERE LOWER(searchedCategory.name) LIKE :search)', $metadata->getAssociationTargetClass('category')),
            sprintf('d.folder IN (SELECT searchedFolder.id FROM %s searchedFolder WHERE LOWER(searchedFolder.name) LIKE :search)', $metadata->getAssociationTargetClass('folder')),
        ];

        $conditions = match ($field) {
            DocumentSearchFieldEnum::Title => $titles,
            DocumentSearchFieldEnum::File => $files,
            DocumentSearchFieldEnum::Text => $texts,
            DocumentSearchFieldEnum::Classification => $classification,
            DocumentSearchFieldEnum::All => [...$titles, ...$files, ...$texts, ...$classification],
        };

        return '('.implode(' OR ', $conditions).')';
    }

    /**
     * The filters that came with the wider search. Each one leaves the query
     * alone when unset, so a listing that passes none is unchanged.
     */
    private function applySearchFilters(QueryBuilder $qb, DocumentSearchFilters $filters): void
    {
        if ($filters->uncategorized) {
            $qb->andWhere('d.category IS NULL');
        }

        if ($filters->untagged) {
            $qb->andWhere('d.tags IS EMPTY');
        }

        if ($filters->addedFrom instanceof DateTimeImmutable) {
            $qb->andWhere('d.createdAt >= :addedFrom')->setParameter('addedFrom', $filters->addedFrom);
        }

        if ($filters->addedTo instanceof DateTimeImmutable) {
            $qb->andWhere('d.createdAt <= :addedTo')->setParameter('addedTo', $filters->addedTo);
        }

        // Square within two percent: a 1080 x 1079 crop is square to anyone
        // looking at it, and would otherwise be filed as landscape.
        match ($filters->orientation) {
            DocumentOrientationEnum::Landscape => $qb->andWhere('d.width > d.height * 1.02'),
            DocumentOrientationEnum::Portrait => $qb->andWhere('d.height > d.width * 1.02'),
            DocumentOrientationEnum::Square => $qb->andWhere('d.width > 0 AND d.height > 0 AND d.width <= d.height * 1.02 AND d.height <= d.width * 1.02'),
            null => null,
        };

        match ($filters->weight) {
            DocumentWeightEnum::Light => $qb->andWhere('d.size < :weightLight')->setParameter('weightLight', DocumentWeightEnum::LIGHT_MAX),
            DocumentWeightEnum::Medium => $qb->andWhere('d.size >= :weightLight AND d.size <= :weightHeavy')
                ->setParameter('weightLight', DocumentWeightEnum::LIGHT_MAX)
                ->setParameter('weightHeavy', DocumentWeightEnum::HEAVY_MIN),
            DocumentWeightEnum::Heavy => $qb->andWhere('d.size > :weightHeavy')->setParameter('weightHeavy', DocumentWeightEnum::HEAVY_MIN),
            null => null,
        };
    }

    /**
     * The listing's order, with each family kept in one piece.
     *
     * Sorted on its own date, an alternate lands wherever its import put it:
     * three copies of one visual imported a week after it ended up pages
     * away from it, and from each other. Every row is sorted on its family's
     * value instead - the original's date, name or size - then the original
     * comes first and its alternates follow by label. A document with no
     * family is a family of one, so nothing else moves.
     *
     * The trash keeps its own order, most recently deleted first: there the
     * question is what was just thrown away, not what belongs together.
     */
    private function orderByFamily(QueryBuilder $qb, bool $trashed, string $sort, string $direction): void
    {
        $order = 'asc' === $direction ? Order::Ascending->value : Order::Descending->value;

        if ($trashed) {
            $qb->orderBy('d.deletedAt', Order::Descending->value);

            return;
        }

        $key = match ($sort) {
            'name' => 'LOWER(COALESCE(original.title, d.title))',
            'size' => 'COALESCE(original.size, d.size)',
            default => 'COALESCE(original.createdAt, d.createdAt)',
        };

        $qb->addSelect($key.' AS HIDDEN familyKey')
            ->addSelect('COALESCE(IDENTITY(d.original), d.id) AS HIDDEN familyId')
            ->addSelect('CASE WHEN d.original IS NULL THEN 0 ELSE 1 END AS HIDDEN familyRank')
            ->orderBy('familyKey', $order)
            ->addOrderBy('familyId', $order)
            ->addOrderBy('familyRank', Order::Ascending->value)
            ->addOrderBy('d.alternateLabel', Order::Ascending->value);
    }

    /**
     * The living alternates of several originals at once, for a page of
     * rows that each show their family.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<Document>> original id => alternates by label
     */
    public function findAlternatesForOriginals(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Document> $alternates */
        $alternates = $this->createQueryBuilder('d')
            ->where('d.original IN (:ids)')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('ids', $ids)
            ->orderBy('d.alternateLabel', Order::Ascending->value)
            ->addOrderBy('d.title', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        $byOriginal = [];
        foreach ($alternates as $alternate) {
            $originalId = $alternate->getOriginal()?->getId();
            if (null !== $originalId) {
                $byOriginal[$originalId][] = $alternate;
            }
        }

        return $byOriginal;
    }

    /**
     * The labels alternates already carry, most used first - offered as
     * suggestions so the library does not end up with both "jaune" and
     * "Jaune".
     *
     * @return list<string>
     */
    public function findAlternateLabels(int $limit = 12): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.alternateLabel AS label', 'COUNT(d.id) AS uses')
            ->where('d.alternateLabel IS NOT NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->groupBy('d.alternateLabel')
            ->orderBy('uses', Order::Descending->value)
            ->addOrderBy('d.alternateLabel', Order::Ascending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['label'], $rows));
    }

    /**
     * The alternates of these documents, trashed ones included - what a move
     * or a deletion "with its alternates" has to carry along.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function findAlternateIdsOf(array $ids, bool $includeTrashed = false): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder('d')
            ->select('d.id')
            ->where('d.original IN (:ids)')
            ->setParameter('ids', $ids);

        if (!$includeTrashed) {
            $qb->andWhere('d.deletedAt IS NULL');
        }

        return array_map(intval(...), array_column($qb->getQuery()->getScalarResult(), 'id'));
    }

    /**
     * How many living alternates each of these documents has, in one query.
     *
     * @param list<int> $ids
     *
     * @return array<int, int> original id => count, absent when none
     */
    public function countAlternatesFor(array $ids, bool $includeTrashed = false): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.original) AS originalId', 'COUNT(d.id) AS total')
            ->where('d.original IN (:ids)')
            ->groupBy('d.original')
            ->setParameter('ids', $ids);

        if (!$includeTrashed) {
            $qb->andWhere('d.deletedAt IS NULL');
        }

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['originalId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The living alternates of a document, by label.
     *
     * @return list<Document>
     */
    public function findAlternatesOf(DocumentInterface $original): array
    {
        /** @var list<Document> $alternates */
        $alternates = $this->createQueryBuilder('d')
            ->leftJoin('d.category', 'c')
            ->leftJoin('d.folder', 'folder')
            ->addSelect('c', 'folder')
            ->where('d.original = :original')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('original', $original)
            ->orderBy('d.alternateLabel', Order::Ascending->value)
            ->addOrderBy('d.title', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        $this->hydrateDocumentTags($alternates);

        return $alternates;
    }

    /** @return list<Document> */
    public function findAllTrashed(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.deletedAt IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Documents trashed long enough ago to be purged.
     *
     * @return list<Document>
     */
    public function findTrashedBefore(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.deletedAt IS NOT NULL')
            ->andWhere('d.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Batch-loads the tags collection for a page of documents to avoid N+1
     * (ManyToMany cannot be joined alongside a LIMIT query).
     *
     * @param list<Document> $documents
     */
    private function hydrateDocumentTags(array $documents): void
    {
        if ([] === $documents) {
            return;
        }

        $ids = array_map(static fn (Document $document): int => $document->getId(), $documents);

        $this->createQueryBuilder('d')
            ->leftJoin('d.tags', 'tag')
            ->addSelect('tag')
            ->where('d.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
