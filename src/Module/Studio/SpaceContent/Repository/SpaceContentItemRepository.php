<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
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
     * The raw counts behind {@see SpaceWorkload}, per space, in two queries
     * whatever the number of spaces.
     *
     * The definitions live on the service; this only turns them into SQL.
     * « Not published » is a step with no role or another role: a step
     * nobody gave a role to is not known to be a published one.
     *
     * @param list<int> $spaceIds
     *
     * @return array<int, array{upcoming: int, withClient: int, lateReview: int, changesRequested: int, missed: int, hasPublishedStep: bool, nextPublication: DateTimeImmutable|null}>
     */
    public function workloadBySpace(array $spaceIds, DateTimeImmutable $now, DateTimeImmutable $horizon): array
    {
        if ([] === $spaceIds) {
            return [];
        }

        $onCalendar = 'i.showOnCalendar = true AND i.scheduledAt IS NOT NULL AND (c.role IS NULL OR c.role <> :published)';
        // « Chez le client » : the Review step when the board has one, any step
        // the client sees when it has none. The card applies the same rule,
        // see AbstractSpaceContentItem::isAtClientStep().
        // One alias per use: the condition is read by two of the sums below,
        // and a subquery alias may appear only once in a statement.
        $withClient = fn (string $alias): string => $onCalendar.sprintf(
            ' AND c.visibleToClient = true AND i.approval = :pending AND (c.role = :review OR NOT EXISTS (SELECT %1$s.id FROM %2$s %1$s WHERE %1$s.space = i.space AND %1$s.role = :review))',
            $alias,
            SpaceContentColumnInterface::class,
        );

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.space) AS space')
            ->addSelect(sprintf('SUM(CASE WHEN %s AND i.scheduledAt >= :now AND i.scheduledAt < :horizon THEN 1 ELSE 0 END) AS upcoming', $onCalendar))
            ->addSelect(sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END) AS withClient', $withClient('rc_with')))
            ->addSelect(sprintf('SUM(CASE WHEN %s AND i.reviewBy IS NOT NULL AND i.reviewBy < :now THEN 1 ELSE 0 END) AS lateReview', $withClient('rc_late')))
            ->addSelect('SUM(CASE WHEN i.approval = :changes AND (c.role IS NULL OR c.role <> :published) THEN 1 ELSE 0 END) AS changesRequested')
            ->addSelect(sprintf('SUM(CASE WHEN %s AND i.scheduledAt < :now THEN 1 ELSE 0 END) AS missed', $onCalendar))
            ->addSelect(sprintf('MIN(CASE WHEN %s AND i.scheduledAt >= :now THEN i.scheduledAt ELSE :none END) AS nextPublication', $onCalendar))
            ->join('i.column', 'c')
            ->where('i.space IN (:spaces)')
            ->groupBy('i.space')
            ->setParameter('spaces', $spaceIds)
            ->setParameter('now', $now)
            ->setParameter('horizon', $horizon)
            ->setParameter('published', SpaceContentColumnRoleEnum::Published)
            ->setParameter('review', SpaceContentColumnRoleEnum::Review)
            ->setParameter('pending', SpaceContentApprovalEnum::Pending)
            ->setParameter('changes', SpaceContentApprovalEnum::ChangesRequested)
            ->setParameter('none', null)
            ->getQuery()
            ->getScalarResult();

        $withPublishedStep = array_flip(array_map(intval(...), $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(c.space)')
            ->from(SpaceContentColumnInterface::class, 'c')
            ->where('c.space IN (:spaces)')
            ->andWhere('c.role = :published')
            ->setParameter('spaces', $spaceIds)
            ->setParameter('published', SpaceContentColumnRoleEnum::Published)
            ->getQuery()
            ->getSingleColumnResult()));

        $bySpace = [];

        foreach ($rows as $row) {
            $id = (int) $row['space'];
            $bySpace[$id] = [
                'upcoming' => (int) $row['upcoming'],
                'withClient' => (int) $row['withClient'],
                'lateReview' => (int) $row['lateReview'],
                'changesRequested' => (int) $row['changesRequested'],
                'missed' => (int) $row['missed'],
                'hasPublishedStep' => isset($withPublishedStep[$id]),
                'nextPublication' => null === $row['nextPublication'] ? null : new DateTimeImmutable((string) $row['nextPublication']),
            ];
        }

        return $bySpace;
    }

    /**
     * The cards on the calendar of these spaces, between two instants, with
     * their step and space loaded: the editorial calendar draws all three.
     *
     * @param list<int> $spaceIds
     *
     * @return list<SpaceContentItemInterface>
     */
    public function findOnCalendar(array $spaceIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ([] === $spaceIds) {
            return [];
        }

        /** @var list<SpaceContentItemInterface> $items */
        $items = $this->createQueryBuilder('i')
            ->addSelect('c', 's')
            ->join('i.column', 'c')
            ->join('i.space', 's')
            ->where('i.space IN (:spaces)')
            ->andWhere('i.showOnCalendar = true')
            ->andWhere('i.scheduledAt >= :from')
            ->andWhere('i.scheduledAt < :to')
            ->setParameter('spaces', $spaceIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.scheduledAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * Every card of these spaces, with its step and space loaded, dated or not.
     *
     * @param list<int> $spaceIds
     *
     * @return list<SpaceContentItemInterface>
     */
    public function findForSpaces(array $spaceIds): array
    {
        if ([] === $spaceIds) {
            return [];
        }

        /** @var list<SpaceContentItemInterface> $items */
        $items = $this->createQueryBuilder('i')
            ->addSelect('c', 's')
            ->join('i.column', 'c')
            ->join('i.space', 's')
            ->where('i.space IN (:spaces)')
            ->setParameter('spaces', $spaceIds)
            ->orderBy('i.scheduledAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * Those of these cards that belong to these spaces.
     *
     * @param list<int> $itemIds
     * @param list<int> $spaceIds
     *
     * @return list<int>
     */
    public function idsInSpaces(array $itemIds, array $spaceIds): array
    {
        if ([] === $itemIds || [] === $spaceIds) {
            return [];
        }

        return array_map(intval(...), $this->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.id IN (:items)')
            ->andWhere('i.space IN (:spaces)')
            ->setParameter('items', $itemIds)
            ->setParameter('spaces', $spaceIds)
            ->getQuery()
            ->getSingleColumnResult());
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

    /**
     * Les cartes dont le titre contient le terme, dans les espaces donnés.
     *
     * `$spaceIds` à null veut dire « tous les espaces » ; une liste vide ne
     * rend rien. L'espace et l'étape viennent avec la carte, parce que la
     * recherche globale les affiche sous son titre. Les plus récemment
     * touchées d'abord : une carte se cherche le plus souvent parce qu'on
     * vient d'y travailler.
     *
     * @param list<int>|null $spaceIds
     *
     * @return list<SpaceContentItemInterface>
     */
    public function searchByTitle(string $term, ?array $spaceIds, int $limit): array
    {
        if ('' === mb_trim($term) || [] === $spaceIds) {
            return [];
        }

        $builder = $this->createQueryBuilder('i')
            ->addSelect('s', 'col')
            ->join('i.space', 's')
            ->join('i.column', 'col')
            ->where('LOWER(i.title) LIKE :term')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('i.updatedAt', Order::Descending->value)
            ->addOrderBy('i.id', Order::Descending->value)
            ->setMaxResults($limit);

        if (null !== $spaceIds) {
            $builder->andWhere('s.id IN (:ids)')->setParameter('ids', $spaceIds);
        }

        return $builder->getQuery()->getResult();
    }
}
