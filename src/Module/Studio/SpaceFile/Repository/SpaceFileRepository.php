<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
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
     * @return list<SpaceFileInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.space = :space')
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

    /** Whether this space already holds that document, so it is not filed twice. */
    public function has(CustomerSpaceInterface $space, DocumentInterface $document): bool
    {
        return null !== $this->findOneBy(['space' => $space, 'document' => $document]);
    }
}
