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

    /**
     * The comments' text of several notes at once, for the search.
     *
     * @param list<int> $noteIds
     *
     * @return array<int, list<string>> note id => bodies
     */
    public function bodiesForNotes(array $noteIds): array
    {
        if ([] === $noteIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.note) AS noteId', 'c.body AS body', 'c.quote AS quote')
            ->where('c.note IN (:ids)')
            ->setParameter('ids', $noteIds)
            ->getQuery()
            ->getArrayResult();

        $bodies = [];
        foreach ($rows as $row) {
            $bodies[(int) $row['noteId']][] = mb_trim(($row['quote'] ?? '').' '.$row['body']);
        }

        return $bodies;
    }
}
