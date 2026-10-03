<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Repository;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarkdownNoteRevision>
 */
class MarkdownNoteRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarkdownNoteRevision::class);
    }

    /** @return list<MarkdownNoteRevision> les plus récentes d'abord */
    public function findForNote(MarkdownNoteInterface $note): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.note = :note')
            ->setParameter('note', $note)
            ->orderBy('r.createdAt', Order::Descending->value)
            ->addOrderBy('r.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    public function findLatestForNote(MarkdownNoteInterface $note): ?MarkdownNoteRevision
    {
        return $this->createQueryBuilder('r')
            ->where('r.note = :note')
            ->setParameter('note', $note)
            ->orderBy('r.createdAt', Order::Descending->value)
            ->addOrderBy('r.id', Order::Descending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneForNote(MarkdownNoteInterface $note, int $id): ?MarkdownNoteRevision
    {
        return $this->findOneBy(['note' => $note, 'id' => $id]);
    }

    /** Ne garde que les `$keep` plus récentes. */
    public function pruneBeyond(MarkdownNoteInterface $note, int $keep): void
    {
        $ids = array_map(
            static fn (MarkdownNoteRevision $revision): int => (int) $revision->getId(),
            array_slice($this->findForNote($note), $keep),
        );

        if ([] === $ids) {
            return;
        }

        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
