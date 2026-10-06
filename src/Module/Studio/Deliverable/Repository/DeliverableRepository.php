<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Slides\Entity\SlideInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function array_map;
use function mb_trim;
use function sprintf;

/**
 * @extends ResolveTargetEntityRepository<DeliverableInterface>
 */
class DeliverableRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deliverable::class, DeliverableInterface::class);
    }

    /**
     * A space's deliverables, the last touched first.
     *
     * `$visibleOnly` is what the client's page calls: a closed deliverable
     * never leaves the server, rather than being hidden on display. The studio
     * calls the same method without the flag, hence a single sort.
     *
     * @return list<DeliverableInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space, bool $visibleOnly = false): array
    {
        $builder = $this->createQueryBuilder('d')
            ->leftJoin('d.thumbnail', 't')
            ->addSelect('t')
            ->where('d.space = :space')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('space', $space)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value);

        if ($visibleOnly) {
            $builder->andWhere('d.visibleToClient = true');
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * The rows of a space's list, without their body.
     *
     * A deliverable carries its grid and its content as JSON, up to a hundred
     * kilobytes or so for a full audit; the space page only shows the title,
     * the state and the image. Reading only the columns avoids hydrating and
     * decoding every body each time a tab is opened that is not even the
     * deliverables tab. The same sort as {@see self::findForSpace()}.
     *
     * @return list<array{id: int, title: string, summary: ?string, format: string, visibleToClient: bool, updatedAt: DateTimeImmutable, thumbnailId: ?int}>
     */
    public function findRowsForSpace(CustomerSpaceInterface $space, bool $visibleOnly = false): array
    {
        $builder = $this->createQueryBuilder('d')
            ->select('d.id AS id, d.title AS title, d.summary AS summary, d.format AS format, d.visibleToClient AS visibleToClient, d.updatedAt AS updatedAt, IDENTITY(d.thumbnail) AS thumbnailId')
            ->where('d.space = :space')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('space', $space)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value);

        if ($visibleOnly) {
            $builder->andWhere('d.visibleToClient = true');
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'summary' => null === $row['summary'] ? null : (string) $row['summary'],
            'format' => $row['format'] instanceof DeliverableFormatEnum ? $row['format']->value : (string) $row['format'],
            'visibleToClient' => (bool) $row['visibleToClient'],
            'updatedAt' => $row['updatedAt'],
            'thumbnailId' => null === $row['thumbnailId'] ? null : (int) $row['thumbnailId'],
        ], $builder->getQuery()->getArrayResult());
    }

    /** This one, provided it belongs to this space: an address does not cross spaces. */
    public function findInSpace(CustomerSpaceInterface $space, int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.space = :space')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('space', $space)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A person's personal deliverables, without a space, the last touched
     * first.
     *
     * An administrator also finds there the personal deliverables left
     * without an author: they take them in, see {@see DeliverableAccess::adopts()}.
     *
     * @return list<DeliverableInterface>
     */
    public function findPersonalFor(CoreUserInterface $user): array
    {
        $builder = $this->standalone(DeliverableScopeEnum::Personal);

        if (DeliverableAccess::isAdmin($user)) {
            $builder->andWhere('d.owner = :owner OR d.owner IS NULL');
        } else {
            $builder->andWhere('d.owner = :owner');
        }

        return $builder->setParameter('owner', $user)->getQuery()->getResult();
    }

    /**
     * Studio's shared deliverables, the whole team's.
     *
     * @return list<DeliverableInterface>
     */
    public function findShared(): array
    {
        return $this->standalone(DeliverableScopeEnum::Shared)->getQuery()->getResult();
    }

    /**
     * The deliverables whose title contains this term, most recent first:
     * candidates for the global search, which the caller filters by what the
     * person may read, something SQL cannot express.
     *
     * @return list<DeliverableInterface>
     */
    public function searchByTitle(string $term, int $limit): array
    {
        if ('' === mb_trim($term)) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->leftJoin('d.space', 's')
            ->addSelect('s')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            ->where('LOWER(d.title) LIKE :term')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The presentations with a slide containing this term, most recent
     * first: candidates for the global search, then filtered like the title
     * ones.
     *
     * A slide's words are in its JSON, at every depth (a free slide keeps its
     * texts in its elements): only values that are strings are read, never
     * keys, otherwise searching "title" would find every presentation. The
     * speaker notes are not included: they belong to whoever presents.
     *
     * @return list<DeliverableInterface>
     */
    public function searchBySlideText(string $term, int $limit): array
    {
        if ('' === mb_trim($term)) {
            return [];
        }

        $entityManager = $this->getEntityManager();
        $deliverables = $this->getClassMetadata();
        $slides = $entityManager->getClassMetadata(SlideInterface::class);

        $ids = $entityManager->getConnection()->fetchFirstColumn(
            sprintf(
                <<<'SQL'
                    SELECT d.id FROM %1$s d
                     WHERE d.deleted_at IS NULL AND d.format = :format
                       AND EXISTS (SELECT 1 FROM %2$s s, jsonb_path_query(s.%3$s::jsonb, 'strict $.**') AS value
                                    WHERE s.deliverable_id = d.id AND jsonb_typeof(value) = 'string' AND LOWER(value #>> '{}') LIKE :term)
                     ORDER BY d.updated_at DESC, d.id DESC
                     LIMIT %4$d
                    SQL,
                $deliverables->getTableName(),
                $slides->getTableName(),
                $slides->getColumnName('content'),
                $limit,
            ),
            ['format' => DeliverableFormatEnum::Slides->value, 'term' => LikePattern::contains($term)],
            ['format' => ParameterType::STRING, 'term' => ParameterType::STRING],
        );

        if ([] === $ids) {
            return [];
        }

        $byId = [];
        foreach ($this->findBy(['id' => array_map(intval(...), $ids)]) as $deliverable) {
            $byId[(int) $deliverable->getId()] = $deliverable;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[(int) $id])) {
                $ordered[] = $byId[(int) $id];
            }
        }

        return $ordered;
    }

    /**
     * A live deliverable, from Studio or from a space: the one you open, edit,
     * send. A trashed deliverable is no longer there for anyone, and answers
     * like an unknown id.
     */
    public function findLive(int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** A trashed deliverable, from Studio or from a space: what you restore or destroy for good. */
    public function findTrashed(int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.deletedAt IS NOT NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Everything the trash holds, the last arrived first. The caller keeps
     * what the person may see: SQL cannot express who reads a personal
     * deliverable or a space's.
     *
     * @return list<DeliverableInterface>
     */
    public function findAllTrashed(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.space', 's')
            ->addSelect('s')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            ->where('d.deletedAt IS NOT NULL')
            ->orderBy('d.deletedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The ones in the trash since before this date: the scheduled purge
     * destroys them.
     *
     * @return list<DeliverableInterface>
     */
    public function findTrashedBefore(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.deletedAt IS NOT NULL')
            ->andWhere('d.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every deliverable, from Studio and from spaces, with its image: enough
     * to tell which media library documents they display.
     *
     * Walked through rather than joined: a deliverable keeps its images as
     * ids in its JSON, like a slide. No order, it is a count.
     *
     * @return list<DeliverableInterface>
     */
    public function findAllForUsage(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.thumbnail', 't')
            ->addSelect('t')
            ->leftJoin('d.space', 's')
            ->addSelect('s')
            // A slideshow's slides carry its images: loaded with it, rather
            // than one query per deliverable.
            ->leftJoin('d.slides', 'sl')
            ->addSelect('sl')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many Studio deliverables this person sees: the shared ones, and
     * their own personal ones (the authorless ones too, for whoever takes them
     * in). A count, for the dashboard, without hydrating anything.
     */
    public function countStandaloneFor(CoreUserInterface $user): int
    {
        $builder = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.space IS NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.scope = :shared OR d.owner = :owner'.(DeliverableAccess::isAdmin($user) ? ' OR d.owner IS NULL' : ''))
            ->setParameter('shared', DeliverableScopeEnum::Shared)
            ->setParameter('owner', $user);

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * The live Studio deliverables written for this client, the last touched
     * first: the proposal made before their space existed. The ones in their
     * space do not count here, the space lists them itself.
     *
     * All shelves together: the caller keeps what the person may read,
     * something SQL cannot express.
     *
     * @return list<DeliverableInterface>
     */
    public function findLiveStandaloneForCustomer(CustomerInterface $customer): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            ->where('d.space IS NULL')
            ->andWhere('d.customer = :customer')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('customer', $customer)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The live Studio presentations, by title: what the "présentation" zone of
     * a site page offers. The caller filters by what the person may read.
     *
     * @return list<DeliverableInterface>
     */
    public function findLiveStandaloneSlidesByTitle(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            ->where('d.space IS NULL')
            ->andWhere('d.format = :format')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('format', DeliverableFormatEnum::Slides)
            ->orderBy('d.title', Order::Ascending->value)
            ->addOrderBy('d.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The live Studio templates, from both shelves, by title: what « Partir
     * d'un modèle » offers in a space's Deliverables tab. The caller filters
     * by what the reader may read, a colleague's personal template included.
     *
     * @return list<DeliverableInterface>
     */
    public function findLiveStandaloneTemplates(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            ->leftJoin('d.category', 'c')
            ->addSelect('c')
            ->where('d.space IS NULL')
            ->andWhere('d.template = true')
            ->andWhere('d.deletedAt IS NULL')
            ->orderBy('d.title', Order::Ascending->value)
            ->addOrderBy('d.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /** A deliverable without a space: a space's deliverables only open through it. */
    public function findStandalone(int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.space IS NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function standalone(DeliverableScopeEnum $scope): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            // Each card's category, client and image, in the same query.
            ->leftJoin('d.category', 'c')
            ->addSelect('c')
            ->leftJoin('d.customer', 'cu')
            ->addSelect('cu')
            ->leftJoin('d.thumbnail', 't')
            ->addSelect('t')
            ->where('d.space IS NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.scope = :scope')
            ->setParameter('scope', $scope)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value);
    }
}
