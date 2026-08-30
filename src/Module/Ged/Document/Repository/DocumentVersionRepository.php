<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
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
