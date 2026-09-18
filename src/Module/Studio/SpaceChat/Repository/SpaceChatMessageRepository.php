<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;
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
     * Ce qu'une remontée dans l'historique rapporte d'un coup.
     *
     * Plus petit que la fenêtre d'ouverture, et volontairement : la première
     * charge doit remplir l'écran, les suivantes doivent arriver avant que le
     * pouce ait fini son geste. Cinquante messages tiennent dans une réponse
     * qu'on ne voit pas passer.
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
     * Ce qui précède un message, du plus récent au plus ancien puis remis à
     * l'endroit.
     *
     * **La page suivante se demande par un identifiant, jamais par un
     * décalage.** Un `OFFSET` compte des lignes depuis le début : une
     * conversation où quelqu'un écrit pendant qu'on remonte décale tout ce qui
     * suit, et le lecteur voit deux fois le même message ou en saute un. Le
     * repère est donc la ligne d'où l'on part, qui ne bouge pas.
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
}
