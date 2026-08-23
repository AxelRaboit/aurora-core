<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function array_fill_keys;
use function count;
use function is_array;

/**
 * @extends ResolveTargetEntityRepository<PostInterface>
 */
class PostRepository extends ResolveTargetEntityRepository
{
    use PaginationTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class, PostInterface::class);
    }

    /**
     * The backend list. Filters combine with AND; within a filter the values
     * combine with OR, so "type: page or article, tagged travel or food"
     * reads the way the checkboxes look.
     *
     * @param list<int>    $postTypeIds
     * @param list<int>    $termIds
     * @param list<string> $statuses
     *
     * @return array{items: list<PostInterface>, total: int, page: int, totalPages: int}
     */
    public function findPaginated(
        int $page,
        string $locale,
        int $limit = 20,
        ?string $search = null,
        array $postTypeIds = [],
        bool $trashed = false,
        ?int $authorId = null,
        array $termIds = [],
        array $statuses = [],
    ): array {
        $items = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->leftJoin('p.postType', 'pt')
            ->addSelect('t', 'pt')
            ->setParameter('locale', $locale)
            ->orderBy('p.createdAt', Order::Descending->value);

        $count = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->setParameter('locale', $locale);

        $this->applyFilters($items, $count, $postTypeIds, $trashed, $authorId, $termIds, $statuses);

        if (null !== $search && '' !== mb_trim($search)) {
            $ranked = $this->applySearch($items, $count, $search);
            if (null === $ranked) {
                return ['items' => [], 'total' => 0, 'page' => max(1, $page), 'totalPages' => 1];
            }
        }

        $result = $this->paginate($items, $count, $page, $limit);
        $this->hydrateCollections($result['items']);

        return $result;
    }

    public function findPublishedBySlug(string $slug, string $locale): ?PostInterface
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.translations', 't')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.slug = :slug')
            ->andWhere('p.status = :status')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('locale', $locale)
            ->setParameter('slug', $slug)
            ->setParameter('status', PostStatusEnum::Published)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A public listing for one post type. Only published, never trashed, and
     * only rows that have something to show in this locale - a post with no
     * translation here would render as a card with no title.
     *
     * @return array{items: list<PostInterface>, total: int, page: int, totalPages: int}
     */
    public function findPublishedByPostType(int $postTypeId, int $page, int $limit, string $locale, ?string $search = null): array
    {
        $items = $this->publishedQueryBuilder($locale)
            ->addSelect('t')
            ->andWhere('p.postType = :postType')
            ->setParameter('postType', $postTypeId)
            ->orderBy('p.publishedAt', Order::Descending->value)
            ->addOrderBy('p.id', Order::Descending->value);

        $count = $this->publishedQueryBuilder($locale)
            ->select('COUNT(p.id)')
            ->andWhere('p.postType = :postType')
            ->setParameter('postType', $postTypeId);

        if (null !== $search && '' !== mb_trim($search)) {
            $matched = array_values(array_unique([
                ...$this->fullTextPostIds($search),
                ...$this->titleSlugMatchIds($search),
            ]));

            if ([] === $matched) {
                return ['items' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1];
            }

            foreach ([$items, $count] as $queryBuilder) {
                $queryBuilder->andWhere('p.id IN (:ids)')->setParameter('ids', $matched);
            }
        }

        return $this->paginate($items, $count, $page, $limit);
    }

    /**
     * @return array{items: list<PostInterface>, total: int, page: int, totalPages: int}
     */
    public function findPublishedByTerm(int $termId, int $page, int $limit, string $locale): array
    {
        $items = $this->publishedQueryBuilder($locale)
            ->addSelect('t')
            ->innerJoin('p.terms', 'term')
            ->andWhere('term.id = :termId')
            ->setParameter('termId', $termId)
            ->orderBy('p.publishedAt', Order::Descending->value)
            ->addOrderBy('p.id', Order::Descending->value);

        $count = $this->publishedQueryBuilder($locale)
            ->select('COUNT(p.id)')
            ->innerJoin('p.terms', 'term')
            ->andWhere('term.id = :termId')
            ->setParameter('termId', $termId);

        return $this->paginate($items, $count, $page, $limit);
    }

    /**
     * The shape every public listing starts from. An INNER JOIN on the
     * translation is what drops posts untranslated in this locale.
     */
    private function publishedQueryBuilder(string $locale): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->andWhere('p.status = :published')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('locale', $locale)
            ->setParameter('published', PostStatusEnum::Published);
    }

    /**
     * Post ids matching the full-text index, best first. Raw SQL because
     * ts_rank has no DQL equivalent; the column it reads is maintained by
     * PostTextExtractor on every save.
     *
     * @return list<int>
     */
    public function fullTextPostIds(string $search, int $limit = 200): array
    {
        $sql = <<<'SQL'
            SELECT pt.post_id,
                   MAX(ts_rank(to_tsvector('simple', coalesce(pt.search_content, '')), websearch_to_tsquery('simple', :q))) AS rank
            FROM core_post_translations pt
            WHERE to_tsvector('simple', coalesce(pt.search_content, '')) @@ websearch_to_tsquery('simple', :q)
            GROUP BY pt.post_id
            ORDER BY rank DESC
            LIMIT :max
            SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            ['q' => $search, 'max' => $limit],
            ['q' => ParameterType::STRING, 'max' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): int => (int) $row['post_id'], $rows);
    }

    /** @return list<PostInterface> */
    public function findAllTrashed(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.deletedAt IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Posts whose scheduled time has come. Ordered oldest-first so a backlog
     * publishes in the order it was queued rather than however the rows come
     * back.
     *
     * @return list<PostInterface>
     */
    public function findDueForPublication(DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.scheduledAt IS NOT NULL')
            ->andWhere('p.scheduledAt <= :now')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('status', PostStatusEnum::Scheduled)
            ->setParameter('now', $now)
            ->orderBy('p.scheduledAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every published post with its translations and type, for the sitemap
     * and the feed.
     *
     * Joined and selected in one go: the sitemap walks every translation of
     * every post, and letting Doctrine lazy-load them would be one query per
     * post per locale on the one route a crawler hits hardest.
     *
     * @return list<PostInterface>
     */
    public function findAllPublishedForSitemap(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't')
            ->leftJoin('p.postType', 'pt')
            ->addSelect('t', 'pt')
            ->where('p.status = :status')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('status', PostStatusEnum::Published)
            ->orderBy('p.publishedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many live posts sit in each status, for the dashboard.
     *
     * One grouped query rather than one COUNT per status, and rows are only
     * returned for statuses that have posts - the caller fills the gaps, so
     * adding a status to the enum cannot leave a hole here.
     *
     * @return array<string, int> status value → count, trashed posts excluded
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.status AS status', 'COUNT(p.id) AS total')
            ->where('p.deletedAt IS NULL')
            ->groupBy('p.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'];
            $counts[$status instanceof PostStatusEnum ? $status->value : (string) $status] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Posts published per month, over the last `$months` including this one.
     *
     * Keyed `YYYY-MM`, and **every month is present**, with a zero where nothing
     * went out. A series that only carries the months with activity is not a
     * sparser chart, it is a wrong one: the gaps close up and a quiet August
     * reads as a busy one sitting next to July.
     *
     * Counts `published_at`, not `created_at` - the question is when the site
     * published, not when someone opened an editor. A draft written in March and
     * published in May belongs to May, and one never published belongs nowhere.
     * Soft-deleted rows are out for the same reason they are out of the list.
     *
     * Raw SQL because the truncation to a month has no DQL equivalent, following
     * {@see UserRepository::countGroupedByStoredRoles()} which does the same for
     * the same kind of reason. Postgres-only, which this project already is.
     *
     * @return array<string, int>
     */
    public function countPublishedByMonth(int $months = 12): array
    {
        $months = max(1, $months);
        $first = new DateTimeImmutable(sprintf('first day of -%d month', $months - 1));

        $series = [];
        for ($offset = 0; $offset < $months; ++$offset) {
            $series[$first->modify(sprintf('+%d month', $offset))->format('Y-m')] = 0;
        }

        $sql = <<<'SQL'
            SELECT to_char(date_trunc('month', published_at), 'YYYY-MM') AS month, COUNT(*) AS total
            FROM core_posts
            WHERE published_at IS NOT NULL
              AND deleted_at IS NULL
              AND published_at >= :since
            GROUP BY 1
            SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'since' => $first->format('Y-m-d 00:00:00'),
        ]);

        foreach ($rows as $row) {
            $month = (string) $row['month'];
            // A row outside the window cannot happen given the WHERE, but a
            // month that is not in the series is dropped rather than appended:
            // the series defines the axis, not the data.
            if (array_key_exists($month, $series)) {
                $series[$month] = (int) $row['total'];
            }
        }

        return $series;
    }

    /**
     * How many pictures each of these publications has in its gallery.
     *
     * For the gallery screen, where the useful question about a row is whether it
     * still needs photographs - a list of titles with no counts cannot answer it,
     * and opening each one to find out is the work the screen exists to save.
     *
     * Keyed by id, and **every id asked for is present**, with a zero where the
     * gallery is empty or was never configured. A map that omits the empty ones
     * would make the caller write the same `?? 0` at every use, which is where a
     * missing count silently reads as "not loaded yet".
     *
     * Counted in PHP rather than with Postgres' JSON functions: `galleryLayout` is
     * a JSON column, `jsonb_array_length` would need a cast and a guard for the
     * `[]` default, and the rows are one page of publications. Not worth
     * Postgres-only SQL for a number next to a title.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function galleryItemCounts(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('p.id AS id', 'p.galleryLayout AS layout')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys($ids, 0);

        foreach ($rows as $row) {
            $layout = $row['layout'];
            $items = is_array($layout) ? ($layout['items'] ?? []) : [];

            $counts[(int) $row['id']] = is_array($items) ? count($items) : 0;
        }

        return $counts;
    }

    public function countTrashed(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.deletedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<PostInterface> */
    public function findTrashedBefore(DateTimeImmutable $threshold): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.deletedAt IS NOT NULL')
            ->andWhere('p.deletedAt <= :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int>    $postTypeIds
     * @param list<int>    $termIds
     * @param list<string> $statuses
     */
    private function applyFilters(
        QueryBuilder $items,
        QueryBuilder $count,
        array $postTypeIds,
        bool $trashed,
        ?int $authorId,
        array $termIds,
        array $statuses,
    ): void {
        $both = [$items, $count];

        $trashCondition = $trashed ? 'p.deletedAt IS NOT NULL' : 'p.deletedAt IS NULL';
        foreach ($both as $queryBuilder) {
            $queryBuilder->andWhere($trashCondition);
        }

        if ([] !== $postTypeIds) {
            foreach ($both as $queryBuilder) {
                $queryBuilder->andWhere('p.postType IN (:postTypeIds)')->setParameter('postTypeIds', $postTypeIds);
            }
        }

        if ([] !== $statuses) {
            foreach ($both as $queryBuilder) {
                $queryBuilder->andWhere('p.status IN (:statuses)')->setParameter('statuses', $statuses);
            }
        }

        if (null !== $authorId) {
            foreach ($both as $queryBuilder) {
                $queryBuilder->andWhere('p.author = :authorId')->setParameter('authorId', $authorId);
            }
        }

        if ([] !== $termIds) {
            foreach ($both as $queryBuilder) {
                $queryBuilder->innerJoin('p.terms', 'filterTerm')
                    ->andWhere('filterTerm.id IN (:termIds)')
                    ->setParameter('termIds', $termIds);
            }
        }
    }

    /**
     * Narrows both queries to what the search matched, and orders the list by
     * relevance. Returns null when nothing matched, so the caller can skip the
     * queries entirely rather than run an `IN ()` that can never be true.
     *
     * @return list<int>|null
     */
    private function applySearch(QueryBuilder $items, QueryBuilder $count, string $search): ?array
    {
        $ranked = $this->fullTextPostIds($search);
        $matched = array_values(array_unique([...$ranked, ...$this->titleSlugMatchIds($search)]));

        if ([] === $matched) {
            return null;
        }

        foreach ([$items, $count] as $queryBuilder) {
            $queryBuilder->andWhere('p.id IN (:searchIds)')->setParameter('searchIds', $matched);
        }

        // Full-text hits first, in rank order; everything the LIKE found and
        // the index did not lands after them.
        if ([] !== $ranked) {
            $case = 'CASE p.id';
            foreach ($ranked as $index => $id) {
                $case .= sprintf(' WHEN %d THEN %d', $id, $index);
            }

            $case .= ' ELSE '.count($ranked).' END';

            $items->resetDQLPart('orderBy')->orderBy($case, Order::Ascending->value);
        }

        return $ranked;
    }

    /**
     * Titles and slugs the index cannot help with - a slug is one token, and
     * pasting a whole URL is how an editor looks for the page behind it, so
     * the last path segment is what gets matched.
     *
     * @return list<int>
     */
    private function titleSlugMatchIds(string $search): array
    {
        $term = $search;
        if (str_contains($search, '/')) {
            $segments = array_values(array_filter(explode('/', $search), static fn (string $s): bool => '' !== $s));
            if ([] !== $segments) {
                $term = end($segments);
            }
        }

        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.id AS post_id')
            ->innerJoin('p.translations', 'ts')
            ->andWhere('p.deletedAt IS NULL')
            ->andWhere('LOWER(ts.title) LIKE :pattern OR LOWER(ts.slug) LIKE :pattern')
            ->setParameter('pattern', '%'.mb_strtolower(addcslashes($term, '%_\\')).'%')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['post_id'], $rows);
    }

    /**
     * Batch-loads the collections the serializer reads, so listing 20 posts
     * costs two queries rather than forty.
     *
     * @param list<PostInterface> $posts
     */
    private function hydrateCollections(array $posts): void
    {
        if ([] === $posts) {
            return;
        }

        $ids = array_map(static fn (PostInterface $post): ?int => $post->getId(), $posts);

        foreach (['terms', 'relatedPosts'] as $association) {
            $this->createQueryBuilder('p')
                ->leftJoin('p.'.$association, 'assoc')
                ->addSelect('assoc')
                ->where('p.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getResult();
        }
    }
}
