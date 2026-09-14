<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Service\ImageVariantGenerator;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentRelocator;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<DocumentInterface> */
class DocumentRepository extends ResolveTargetEntityRepository
{
    use PaginationTrait;

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
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.category', 'c')
            ->leftJoin('d.folder', 'folder')
            ->addSelect('c', 'folder')
            ->orderBy($trashed ? 'd.deletedAt' : 'd.createdAt', Order::Descending->value);
        $countQb = $this->createQueryBuilder('d')->select('COUNT(d.id)');

        // The trash is the same listing with the condition flipped, not a
        // second finder: every filter above keeps working inside it, and a
        // document can only ever be on one side of this line.
        $trashCondition = $trashed ? 'd.deletedAt IS NOT NULL' : 'd.deletedAt IS NULL';
        $qb->andWhere($trashCondition);
        $countQb->andWhere($trashCondition);

        if (null !== $search && '' !== $search) {
            $pattern = '%'.mb_strtolower($search).'%';
            $qb->andWhere('LOWER(d.title) LIKE :search OR LOWER(d.reference) LIKE :search')->setParameter('search', $pattern);
            $countQb->andWhere('LOWER(d.title) LIKE :search OR LOWER(d.reference) LIKE :search')->setParameter('search', $pattern);
        }

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

        $result = $this->paginate($qb, $countQb, $page, $limit);
        $this->hydrateDocumentTags($result['items']);

        return $result;
    }

    /**
     * Cheap LIKE-based search over `title` + `original_name`, capped at
     * `$limit` rows. Powers the global backend search controller's
     * "Documents" pane (formerly served by the Media library).
     *
     * @return list<Document>
     */
    /**
     * How many documents currently live on a given backend.
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
     * Same question, asked of one backend only.
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
     * withheld while its `medium` variant is not has been published by
     * accident:
     *
     *  - the document's own file, matched on `filePath`;
     *  - its rendered still (a PDF's first page, a film's poster), matched on
     *    `thumbnailPath`, which is a column like the other;
     *  - one of its responsive variants, which live in a JSON column and so
     *    are matched by shape instead - see {@see variantSourcePattern()}.
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
        $queryBuilder = $this->createQueryBuilder('d')
            ->select('d.status')
            ->andWhere('d.deletedAt IS NULL')
            ->setMaxResults(1);

        $variantPattern = $this->variantSourcePattern($path);

        if (null === $variantPattern) {
            $queryBuilder
                ->andWhere('d.filePath = :path OR d.thumbnailPath = :path')
                ->setParameter('path', $path);
        } else {
            // A variant carries its source's basename but not its extension
            // (everything is re-encoded to WebP), so the source is matched on
            // its stem. `ESCAPE` is set because a stem is slugged and could
            // in principle be made to carry a wildcard.
            $queryBuilder
                ->andWhere("d.filePath LIKE :pattern ESCAPE '!'")
                ->setParameter('pattern', $variantPattern);
        }

        /** @var array{status: DocumentStatusEnum}|null $row */
        $row = $queryBuilder->getQuery()->getOneOrNullResult();

        return $row['status'] ?? null;
    }

    /**
     * `ged/2026/05/variants/medium/photo-a1b2.webp` → `ged/2026/05/photo-a1b2.%`,
     * and null for any key that is not shaped like a variant.
     *
     * Mirrors the key {@see ImageVariantGenerator}
     * writes, which is the source's own directory plus `variants/<size>/`.
     * The coupling is real and is pinned by a test: change how a variant is
     * named and this stops finding its owner, which fails open onto the
     * privilege check rather than onto the public.
     */
    private function variantSourcePattern(string $path): ?string
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
     * @return list<Document>
     */
    public function findTrashedWith(int $folderId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.trashedWithFolderId = :id')
            ->setParameter('id', $folderId)
            ->getQuery()
            ->getResult();
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
