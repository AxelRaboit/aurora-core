<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Reading\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Reading\Entity\PostReadingLink;
use Aurora\Module\Editorial\Post\Reading\Entity\PostReadingLinkInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PostReadingLinkInterface>
 */
class PostReadingLinkRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostReadingLink::class, PostReadingLinkInterface::class);
    }

    public function findByToken(string $token): ?PostReadingLinkInterface
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Every link of this publication, newest first, revoked ones included:
     * the panel shows those too, faded, because who could read it once is
     * worth knowing afterwards.
     *
     * @return list<PostReadingLinkInterface>
     */
    public function findForPost(PostInterface $post): array
    {
        /** @var list<PostReadingLinkInterface> $links */
        $links = $this->createQueryBuilder('l')
            ->andWhere('l.post = :post')->setParameter('post', $post)
            ->orderBy('l.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();

        return $links;
    }
}
