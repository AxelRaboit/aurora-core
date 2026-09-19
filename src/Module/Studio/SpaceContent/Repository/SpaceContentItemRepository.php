<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceContentItemInterface>
 */
class SpaceContentItemRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceContentItem::class, SpaceContentItemInterface::class);
    }

    /**
     * Every item of a space, in board order.
     *
     * The whole board in one query, not one per column: a board is read in full
     * every time it is opened, and the page groups the rows itself. The column
     * is joined because each card names the step it is on.
     *
     * Not paginated, deliberately, and this is the sizing that will be revisited
     * first: a space accumulates content for as long as the engagement runs. The
     * day it stops fitting, the answer is a window on `scheduledAt` rather than a
     * page number, because a board is read by period and not by page.
     *
     * @return list<SpaceContentItemInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('c')
            ->join('i.column', 'c')
            ->where('i.space = :space')
            ->setParameter('space', $space)
            ->orderBy('c.position', Order::Ascending->value)
            ->addOrderBy('i.position', Order::Ascending->value)
            ->addOrderBy('i.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /** The place a new card takes: at the bottom of its column. */
    public function nextPosition(SpaceContentColumnInterface $column): int
    {
        $highest = $this->createQueryBuilder('i')
            ->select('MAX(i.position)')
            ->where('i.column = :column')
            ->setParameter('column', $column)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : (int) $highest + 1;
    }

    /**
     * Combien de cartes par verdict du client.
     *
     * C'est le nombre qui dit ce qui attend quelqu'un : une carte en attente
     * dort chez le client, une carte à revoir est revenue au studio.
     *
     * @return array<string, int>
     */
    public function countGroupedByApproval(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.approval AS approval, COUNT(i.id) AS total')
            ->groupBy('i.approval')
            ->getQuery()
            ->getScalarResult();

        $counts = [];

        foreach ($rows as $row) {
            $approval = $row['approval'];
            $counts[$approval instanceof SpaceContentApprovalEnum ? $approval->value : (string) $approval] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Ce qui sort d'ici à une date.
     *
     * **Les mêmes deux conditions que le calendrier**, et pas seulement la
     * date : une carte décochée porte une échéance interne, et la compter ici
     * annoncerait une parution qui n'en est pas une.
     */
    public function countScheduledBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.scheduledAt >= :from')
            ->andWhere('i.scheduledAt < :to')
            ->andWhere('i.showOnCalendar = true')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForSpace(CustomerSpaceInterface $space): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.space = :space')
            ->setParameter('space', $space)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
