<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Preview\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Preview\Entity\PostPreviewToken;
use Aurora\Module\Editorial\Post\Preview\Entity\PostPreviewTokenInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PostPreviewTokenInterface>
 */
class PostPreviewTokenRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostPreviewToken::class, PostPreviewTokenInterface::class);
    }

    /**
     * A token by its value, whatever its state.
     *
     * Not filtered on expiry: the route has to tell an expired token from one that
     * never existed, not to say so on screen - both get the same answer - but so
     * the difference stays available to anything that later wants to count it.
     */
    public function findByToken(string $token): ?PostPreviewTokenInterface
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * The live preview for this post, if there is one.
     *
     * Reused rather than minted per click: a button that hands out a new secret
     * every time it is pressed leaves a trail of live addresses behind, and the
     * person pressing it has no idea they are accumulating.
     */
    public function findLiveFor(PostInterface $post, DateTimeImmutable $now): ?PostPreviewTokenInterface
    {
        return $this->createQueryBuilder('t')
            ->where('t.post = :post')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('post', $post)
            ->setParameter('now', $now)
            // Newest first, so a post that somehow has two gives back the one that
            // lasts longest.
            ->orderBy('t.expiresAt', Order::Descending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Drops every token that has run out.
     *
     * For the scheduled clean-up. An expired token is already refused, so this is
     * housekeeping rather than security - but a table that only grows is a table
     * somebody eventually has to explain.
     */
    public function deleteExpired(DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
