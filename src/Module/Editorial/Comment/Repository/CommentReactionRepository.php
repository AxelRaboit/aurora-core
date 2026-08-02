<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Entity\CommentReaction;
use Aurora\Module\Editorial\Comment\Entity\CommentReactionInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<CommentReactionInterface>
 */
class CommentReactionRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentReaction::class, CommentReactionInterface::class);
    }

    public function findOneForReader(CommentInterface $comment, string $fingerprint): ?CommentReactionInterface
    {
        return $this->findOneBy(['comment' => $comment, 'fingerprint' => $fingerprint]);
    }

    /**
     * Reaction tallies for a whole thread in one query.
     *
     * A page with forty comments and six reaction types would otherwise be
     * 240 counts; this is one, grouped, and the caller fills the zeroes.
     *
     * @param list<int> $commentIds
     *
     * @return array<int, array<string, int>> comment id → type → count
     */
    public function countByComments(array $commentIds): array
    {
        if ([] === $commentIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.comment) AS commentId', 'r.type AS type', 'COUNT(r.id) AS total')
            ->where('r.comment IN (:ids)')
            ->setParameter('ids', $commentIds)
            ->groupBy('r.comment', 'r.type')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $type = $row['type'];
            $counts[(int) $row['commentId']][is_object($type) ? $type->value : (string) $type] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<string, int> type → count */
    public function countByComment(CommentInterface $comment): array
    {
        return $this->countByComments([(int) $comment->getId()])[(int) $comment->getId()] ?? [];
    }
}
