<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Entity\DocumentVersion;
use Aurora\Module\Ged\Document\Entity\DocumentVersionInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<DocumentVersionInterface> */
class DocumentVersionRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentVersion::class, DocumentVersionInterface::class);
    }

    /** @return list<DocumentVersionInterface> */
    public function findByDocument(DocumentInterface $document): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.document = :doc')
            ->setParameter('doc', $document)
            ->orderBy('v.versionNumber', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The file paths of every version of these documents, in one query.
     *
     * Scalars, not entities: the caller is about to remove the documents,
     * and version rows loaded into the unit of work beside them would still
     * point at a document leaving it.
     *
     * @param list<DocumentInterface> $documents
     *
     * @return array<int, list<string>> keyed by document id
     */
    public function findFilePathsByDocument(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.document) AS documentId', 'v.filePath AS filePath')
            ->andWhere('v.document IN (:documents)')
            ->setParameter('documents', $documents)
            ->getQuery()
            ->getArrayResult();

        $paths = [];
        foreach ($rows as $row) {
            if (is_string($row['filePath']) && '' !== $row['filePath']) {
                $paths[(int) $row['documentId']][] = $row['filePath'];
            }
        }

        return $paths;
    }

    /**
     * Versions beyond the most recent $limit (oldest first to delete), so the
     * caller can drop their rows and physical files. Empty when limit <= 0.
     *
     * @return list<DocumentVersionInterface>
     */
    public function findPrunable(DocumentInterface $document, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->createQueryBuilder('v')
            ->andWhere('v.document = :doc')
            ->setParameter('doc', $document)
            ->orderBy('v.versionNumber', Order::Descending->value)
            ->setFirstResult($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Same question, asked of one suite only. See
     * {@see DocumentRepository::filterPathsInUseOnDisk()} for why relocation
     * cannot use the plain form.
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

        /** @var list<array{filePath: string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('DISTINCT v.filePath')
            ->where('v.filePath IN (:paths)')
            ->andWhere('v.storageDisk = :disk')
            ->setParameter('paths', $paths)
            ->setParameter('disk', $disk)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['filePath'], $rows);
    }

    /**
     * Of the given relative paths, the ones a surviving version row still
     * points at. Counterpart of {@see DocumentRepository::filterPathsInUse()}
     * - the two together decide whether a file may be erased.
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

        /** @var list<array{filePath: string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('DISTINCT v.filePath')
            ->where('v.filePath IN (:paths)')
            ->setParameter('paths', $paths)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['filePath'], $rows);
    }

    public function getNextVersionNumber(DocumentInterface $document): int
    {
        $max = $this->createQueryBuilder('v')
            ->select('MAX(v.versionNumber)')
            ->andWhere('v.document = :doc')
            ->setParameter('doc', $document)
            ->getQuery()
            ->getSingleScalarResult();

        return null !== $max ? (int) $max + 1 : 1;
    }
}
