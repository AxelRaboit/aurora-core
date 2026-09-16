<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentCommentInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceContentCommentInterface>
 */
class SpaceContentCommentRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceContentComment::class, SpaceContentCommentInterface::class);
    }

    /**
     * Every message of a space, oldest first, grouped by the card it is on.
     *
     * The whole space in one query rather than one per card: both screens draw
     * every card at once and open one of them, so fetching per card would be a
     * round trip on each click for data already worth having. A space's threads
     * are small - this is a validation loop, not a forum.
     *
     * @return array<int, list<SpaceContentCommentInterface>> keyed by item id
     */
    public function findForSpaceByItem(CustomerSpaceInterface $space): array
    {
        /** @var list<SpaceContentCommentInterface> $comments */
        $comments = $this->createQueryBuilder('c')
            ->join('c.item', 'i')
            ->where('i.space = :space')
            ->setParameter('space', $space)
            ->orderBy('c.createdAt', Order::Ascending->value)
            ->addOrderBy('c.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        $byItem = [];
        foreach ($comments as $comment) {
            $byItem[(int) $comment->getItem()->getId()][] = $comment;
        }

        return $byItem;
    }
}
