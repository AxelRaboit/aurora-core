<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Poll\Repository;

use Aurora\Module\Editorial\Poll\Entity\PollVote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PollVote>
 */
final class PollVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PollVote::class);
    }

    /**
     * Votes per answer index for one poll.
     *
     * @return array<int, int>
     */
    public function tally(int $postId, string $zoneId): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('v.answer AS answer, COUNT(v.id) AS votes')
            ->andWhere('IDENTITY(v.post) = :post')
            ->andWhere('v.zoneId = :zone')
            ->setParameter('post', $postId)
            ->setParameter('zone', $zoneId)
            ->groupBy('v.answer')
            ->getQuery()
            ->getArrayResult();

        $tally = [];
        foreach ($rows as $row) {
            $tally[(int) $row['answer']] = (int) $row['votes'];
        }

        return $tally;
    }

    public function hasVoted(int $postId, string $zoneId, string $voter): bool
    {
        return null !== $this->createQueryBuilder('v')
            ->select('v.id')
            ->andWhere('IDENTITY(v.post) = :post')
            ->andWhere('v.zoneId = :zone')
            ->andWhere('v.voter = :voter')
            ->setParameter('post', $postId)
            ->setParameter('zone', $zoneId)
            ->setParameter('voter', $voter)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
