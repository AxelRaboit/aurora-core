<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Comment\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Comment\Entity\NoteComment;
use Aurora\Module\Notes\Comment\Entity\NoteCommentInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<NoteCommentInterface>
 */
class NoteCommentRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteComment::class, NoteCommentInterface::class);
    }

    /**
     * Every comment of a note, threads and replies, oldest first, with their
     * authors.
     *
     * @return list<NoteCommentInterface>
     */
    public function findForNote(MarkdownNoteInterface $note): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a')
            ->leftJoin('c.author', 'a')
            ->where('c.note = :note')
            ->setParameter('note', $note)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countOpenThreads(MarkdownNoteInterface $note): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.note = :note')
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.resolvedAt IS NULL')
            ->setParameter('note', $note)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
