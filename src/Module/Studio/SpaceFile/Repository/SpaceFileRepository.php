<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFileInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceFileInterface>
 */
class SpaceFileRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceFile::class, SpaceFileInterface::class);
    }

    /**
     * The space's own files, newest first.
     *
     * The same order as the files of its cards, because the two lists sit
     * under one tab and a reader switching between them should not have to
     * re-learn which way time runs.
     *
     * The document comes along: every row shows its title, name, type and
     * size, and a lazy document is a query per file on a page the client
     * reloads after each upload.
     *
     * @return list<SpaceFileInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.document', 'd')
            ->addSelect('d')
            ->where('f.space = :space')
            ->setParameter('space', $space)
            ->orderBy('f.createdAt', Order::Descending->value)
            ->addOrderBy('f.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The files the client's page lists: shown to them, or sent by them.
     *
     * The same order as {@see self::findForSpace()}, and the same rule as
     * {@see SpaceFileInterface::isShownToClient()}, which the route serving a
     * file asks of one row.
     *
     * @return list<SpaceFileInterface>
     */
    public function findShownForSpace(CustomerSpaceInterface $space): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.document', 'd')
            ->addSelect('d')
            ->where('f.space = :space')
            ->andWhere('f.visibleToClient = true OR f.fromClient = true')
            ->setParameter('space', $space)
            ->orderBy('f.createdAt', Order::Descending->value)
            ->addOrderBy('f.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The rows that carry one document, so the library can say who uses it.
     *
     * Joined rather than scanned: the relation is a typed foreign key, and it
     * cascades - deleting the document here does not blank a picture, it
     * removes the row and the file leaves the space with nothing to say it was
     * ever there.
     *
     * @return list<SpaceFileInterface>
     */
    public function findUsingDocument(int $documentId): array
    {
        return $this->createQueryBuilder('f')
            ->where('IDENTITY(f.document) = :document')
            ->setParameter('document', $documentId)
            ->orderBy('f.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many space files carry each of these documents, in one query.
     *
     * The listing of the library draws a badge per row, and asking
     * {@see self::findUsingDocument()} once per row would be one query per
     * document. Grouped here instead, so a page of fifty costs one.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countByDocument(array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }

        /** @var list<array{document: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.document) AS document', 'COUNT(f.id) AS total')
            ->where('IDENTITY(f.document) IN (:documents)')
            ->setParameter('documents', $documentIds)
            ->groupBy('f.document')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['document']] = (int) $row['total'];
        }

        return $counts;
    }

    /** Whether this space already holds that document, so it is not filed twice. */
    public function has(CustomerSpaceInterface $space, DocumentInterface $document): bool
    {
        return null !== $this->findOneBy(['space' => $space, 'document' => $document]);
    }

    /**
     * The space files whose document title or file name contains the term, for
     * the global search.
     *
     * `$spaceIds` as in the other Studio searches: null for every space, a list
     * to narrow, an empty list for nothing. Neither a space in the trash nor a
     * document in the library's trash is searched. The document and the space
     * come along: the result shows the one and names the other.
     *
     * @param list<int>|null $spaceIds
     *
     * @return list<SpaceFileInterface>
     */
    public function search(string $term, ?array $spaceIds, int $limit): array
    {
        if ('' === mb_trim($term) || [] === $spaceIds) {
            return [];
        }

        $builder = $this->createQueryBuilder('f')
            ->addSelect('d', 's')
            ->join('f.document', 'd')
            ->join('f.space', 's')
            ->where('LOWER(d.title) LIKE :term OR LOWER(d.originalName) LIKE :term')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('f.createdAt', Order::Descending->value)
            ->addOrderBy('f.id', Order::Descending->value)
            ->setMaxResults($limit);

        if (null !== $spaceIds) {
            $builder->andWhere('s.id IN (:ids)')->setParameter('ids', $spaceIds);
        }

        return $builder->getQuery()->getResult();
    }
}
