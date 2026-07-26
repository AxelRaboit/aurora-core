<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
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
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.category', 'c')
            ->leftJoin('d.folder', 'folder')
            ->addSelect('c', 'folder')
            ->orderBy('d.createdAt', Order::Descending->value);
        $countQb = $this->createQueryBuilder('d')->select('COUNT(d.id)');

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
    public function searchByName(string $query, int $limit = 10): array
    {
        $pattern = '%'.mb_strtolower($query).'%';

        return $this->createQueryBuilder('d')
            ->where('LOWER(d.title) LIKE :pattern OR LOWER(d.originalName) LIKE :pattern')
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
