<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PostInterface>
 */
class PostRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class, PostInterface::class);
    }

    /** @return list<PostInterface> */
    public function findAllTrashed(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.deletedAt IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Posts whose scheduled time has come. Ordered oldest-first so a backlog
     * publishes in the order it was queued rather than however the rows come
     * back.
     *
     * @return list<PostInterface>
     */
    public function findDueForPublication(DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.scheduledAt IS NOT NULL')
            ->andWhere('p.scheduledAt <= :now')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('status', PostStatusEnum::Scheduled)
            ->setParameter('now', $now)
            ->orderBy('p.scheduledAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /** @return list<PostInterface> */
    public function findTrashedBefore(DateTimeImmutable $threshold): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.deletedAt IS NOT NULL')
            ->andWhere('p.deletedAt <= :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }
}
