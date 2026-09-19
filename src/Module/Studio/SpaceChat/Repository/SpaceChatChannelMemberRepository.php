<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMember;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMemberInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceChatChannelMemberInterface>
 */
class SpaceChatChannelMemberRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceChatChannelMember::class, SpaceChatChannelMemberInterface::class);
    }

    /**
     * Who is in a room, in the order they were added.
     *
     * @return list<SpaceChatChannelMemberInterface>
     */
    public function findForChannel(SpaceChatChannelInterface $channel): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->setParameter('channel', $channel)
            ->orderBy('m.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }
}
