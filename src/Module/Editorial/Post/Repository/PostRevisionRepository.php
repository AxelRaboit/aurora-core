<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostRevision;
use Aurora\Module\Editorial\Post\Entity\PostRevisionInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PostRevisionInterface>
 */
class PostRevisionRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostRevision::class, PostRevisionInterface::class);
    }

    /** @return list<PostRevisionInterface> */
    public function findForPost(PostInterface $post): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.post = :post')
            ->setParameter('post', $post)
            ->orderBy('r.createdAt', Order::Descending->value)
            ->addOrderBy('r.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Drops everything past the newest $limit revisions of a post. Ids are
     * selected first and deleted by id: a DELETE with an offset is not
     * portable, and the two-step keeps the ordering explicit.
     */
    public function pruneOlderThanLimit(PostInterface $post, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $excessIds = $this->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.post = :post')
            ->setParameter('post', $post)
            ->orderBy('r.createdAt', Order::Descending->value)
            ->addOrderBy('r.id', Order::Descending->value)
            ->setFirstResult($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $excessIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $excessIds)
            ->getQuery()
            ->execute();
    }
}
