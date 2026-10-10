<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMemberInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatReadMarker;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatReadMarkerInterface;
use Aurora\Module\Studio\SpaceChat\Enum\SpaceChatChannelKindEnum;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceChatReadMarkerInterface>
 */
class SpaceChatReadMarkerRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceChatReadMarker::class, SpaceChatReadMarkerInterface::class);
    }

    public function findFor(SpaceChatChannelInterface $channel, ?CoreUserInterface $user, ?SpaceAccessLinkInterface $link): ?SpaceChatReadMarkerInterface
    {
        return $this->findOneBy(null !== $user
            ? ['channel' => $channel, 'user' => $user]
            : ['channel' => $channel, 'link' => $link]);
    }

    /**
     * Unread messages per room for one reader, a room with none left out.
     *
     * Unread is after the reader's mark and by somebody else. A room the
     * reader has never opened counts from its first message: it is all new to
     * them, which is what a client given a link to a running conversation
     * should see. `$fromClientOnly` keeps what the client wrote, for the
     * studio's « messages client non lus ».
     *
     * @param list<SpaceChatChannelInterface> $channels
     *
     * @return array<int, int> room id => count
     */
    public function unreadByChannel(array $channels, ?CoreUserInterface $user, ?SpaceAccessLinkInterface $link, bool $fromClientOnly = false): array
    {
        if ([] === $channels || (null === $user && null === $link)) {
            return [];
        }

        $builder = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(m.channel) AS channel, COUNT(m.id) AS unread')
            ->from(SpaceChatMessageInterface::class, 'm')
            ->leftJoin(SpaceChatReadMarkerInterface::class, 'r', 'WITH', null !== $user ? 'r.channel = m.channel AND r.user = :reader' : 'r.channel = m.channel AND r.link = :reader')
            ->where('m.channel IN (:channels)')
            ->andWhere('r.id IS NULL OR m.createdAt > r.readAt')
            ->andWhere(null !== $user ? 'm.authorUser IS NULL OR m.authorUser != :reader' : 'm.authorLink IS NULL OR m.authorLink != :reader')
            ->setParameter('channels', $channels)
            ->setParameter('reader', $user ?? $link)
            ->groupBy('m.channel');

        if ($fromClientOnly) {
            $builder->andWhere('m.fromClient = true');
        }

        $counts = [];
        foreach ($builder->getQuery()->getScalarResult() as $row) {
            $counts[(int) $row['channel']] = (int) $row['unread'];
        }

        return $counts;
    }

    /** @param list<SpaceChatChannelInterface> $channels */
    public function totalUnread(array $channels, ?CoreUserInterface $user, ?SpaceAccessLinkInterface $link, bool $fromClientOnly = false): int
    {
        return array_sum($this->unreadByChannel($channels, $user, $link, $fromClientOnly));
    }

    /**
     * Unread client messages per space, for one person, over the spaces they
     * are a member of: the dashboard's figure, in one query.
     *
     * Only the rooms that person reads: the main room, the topics they were
     * put in, their private conversations.
     *
     * @param list<int> $spaceIds
     *
     * @return array<int, int> space id => count
     */
    public function unreadFromClientsBySpace(array $spaceIds, CoreUserInterface $user): array
    {
        if ([] === $spaceIds) {
            return [];
        }

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(m.space) AS space, COUNT(m.id) AS unread')
            ->from(SpaceChatMessageInterface::class, 'm')
            ->join('m.channel', 'c')
            ->leftJoin(SpaceChatReadMarkerInterface::class, 'r', 'WITH', 'r.channel = m.channel AND r.user = :user')
            ->where('m.space IN (:spaces)')
            ->andWhere('m.fromClient = true')
            ->andWhere('r.id IS NULL OR m.createdAt > r.readAt')
            ->andWhere(sprintf('c.kind = :main OR EXISTS (SELECT cm.id FROM %s cm WHERE cm.channel = c AND cm.user = :user)', SpaceChatChannelMemberInterface::class))
            ->setParameter('spaces', $spaceIds)
            ->setParameter('user', $user)
            ->setParameter('main', SpaceChatChannelKindEnum::Main)
            ->groupBy('m.space')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['space']] = (int) $row['unread'];
        }

        return $counts;
    }
}
