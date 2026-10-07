<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;
use Aurora\Module\Studio\SpaceChat\Enum\SpaceChatChannelKindEnum;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceChatMessageInterface>
 */
class SpaceChatMessageRepository extends ResolveTargetEntityRepository
{
    /**
     * How far back a page opens.
     *
     * A conversation is not a board: it has no natural end, and sending every
     * message a two-year-old space ever held on each page load is how a screen
     * that felt instant in month one stops loading in month twenty. The window
     * is deliberately generous - somebody scrolling up to last month's brief
     * finds it - and everything older is still in the table, still exported,
     * still auditable. What is missing is a way to reach it from the page, and
     * that is the honest limit of this first version.
     */
    public const int WINDOW = 200;

    /**
     * What one step back through the history brings at once.
     *
     * Smaller than the opening window, on purpose: the first load must fill
     * the screen, the next ones must arrive before the thumb has finished its
     * gesture. Fifty messages fit in a response nobody sees go by.
     */
    public const int PAGE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceChatMessage::class, SpaceChatMessageInterface::class);
    }

    /**
     * The tail of a space's conversation, oldest first.
     *
     * Fetched newest-first so the database stops at the window, then turned
     * round here: a reader wants the last hundred messages in reading order,
     * and `LIMIT` on an ascending sort would hand back the first hundred ever
     * written instead.
     *
     * @return list<SpaceChatMessageInterface>
     */
    public function findRecentForSpace(CustomerSpaceInterface $space, int $limit = self::WINDOW): array
    {
        /** @var list<SpaceChatMessageInterface> $newestFirst */
        $newestFirst = $this->createQueryBuilder('m')
            ->where('m.space = :space')
            ->setParameter('space', $space)
            ->orderBy('m.createdAt', Order::Descending->value)
            ->addOrderBy('m.id', Order::Descending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($newestFirst);
    }

    /**
     * What precedes a message, newest to oldest and then put back in order.
     *
     * **The next page is asked for by an id, never by an offset.** An
     * `OFFSET` counts rows from the start: a conversation where someone writes
     * while the reader scrolls up shifts everything after it, and the reader
     * sees the same message twice or skips one. The marker is therefore the
     * row one starts from, which does not move.
     *
     * @return list<SpaceChatMessageInterface>
     */
    public function findBeforeInChannel(SpaceChatChannelInterface $channel, int $beforeId, int $limit = self::PAGE): array
    {
        /** @var list<SpaceChatMessageInterface> $newestFirst */
        $newestFirst = $this->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->andWhere('m.id < :before')
            ->setParameter('channel', $channel)
            ->setParameter('before', $beforeId)
            ->orderBy('m.createdAt', Order::Descending->value)
            ->addOrderBy('m.id', Order::Descending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($newestFirst);
    }

    /**
     * The tail of one room, oldest first.
     *
     * What every panel actually asks for since the conversation grew rooms.
     * The space-wide window above stays: it is what a whole-space export and
     * the activity counters read, and answering those by walking the rooms
     * would be a join per room for a question that has one answer.
     *
     * @return list<SpaceChatMessageInterface>
     */
    public function findRecentForChannel(SpaceChatChannelInterface $channel, int $limit = self::WINDOW): array
    {
        /** @var list<SpaceChatMessageInterface> $newestFirst */
        $newestFirst = $this->createQueryBuilder('m')
            ->where('m.channel = :channel')
            ->setParameter('channel', $channel)
            ->orderBy('m.createdAt', Order::Descending->value)
            ->addOrderBy('m.id', Order::Descending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($newestFirst);
    }

    /**
     * The messages that contain the term, in the rooms this reader has in
     * their list, for the global search.
     *
     * **The room rule is the one of the reader's own list**
     * ({@see SpaceChatChannelRepository::findForUser()}): the main room, which
     * everybody is in, and the rooms they were invited into and have not put
     * away. Being allowed into a space is not being allowed into its internal
     * rooms, and a sentence from a private conversation between two colleagues
     * is exactly what a search result must not show a third one.
     *
     * `$spaceIds` as in the other Studio searches: null for every space, a list
     * to narrow, an empty list for nothing; a space in the trash is never
     * searched. The room and the space come along: the result names both.
     *
     * @param list<int>|null $spaceIds
     *
     * @return list<SpaceChatMessageInterface>
     */
    public function search(string $term, ?array $spaceIds, CoreUserInterface $reader, int $limit): array
    {
        if ('' === mb_trim($term) || [] === $spaceIds) {
            return [];
        }

        $builder = $this->createQueryBuilder('m')
            ->addSelect('c', 's')
            ->join('m.channel', 'c')
            ->join('m.space', 's')
            ->leftJoin('c.members', 'cm', 'WITH', 'cm.user = :reader AND cm.hiddenAt IS NULL')
            ->where('LOWER(m.body) LIKE :term')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('c.kind = :main OR cm.id IS NOT NULL')
            ->setParameter('term', LikePattern::contains($term))
            ->setParameter('reader', $reader)
            ->setParameter('main', SpaceChatChannelKindEnum::Main)
            ->orderBy('m.createdAt', Order::Descending->value)
            ->addOrderBy('m.id', Order::Descending->value)
            ->setMaxResults($limit);

        if (null !== $spaceIds) {
            $builder->andWhere('s.id IN (:ids)')->setParameter('ids', $spaceIds);
        }

        return $builder->getQuery()->getResult();
    }
}
