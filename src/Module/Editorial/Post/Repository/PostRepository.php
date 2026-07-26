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
use Doctrine\Persistence\ManagerRegistry;

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
     * @param list<int>    $postTypeIds when non-empty, restricts to posts of those types (OR logic)
     * @param list<string> $statuses    when non-empty, restricts to posts with those statuses (OR logic)
     * @param list<int>    $termIds     when non-empty, only posts tagged with ALL listed term IDs are returned
     */
    public function findPaginated(int $page, string $locale, int $limit = 20, ?string $search = null, array $postTypeIds = [], bool $trashed = false, ?int $authorId = null, array $termIds = [], array $statuses = []): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->leftJoin('p.postType', 'pt')
            ->addSelect('t', 'pt')
            ->setParameter('locale', $locale)
            ->orderBy('p.createdAt', Order::Descending->value);

        $countQueryBuilder = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->setParameter('locale', $locale);

        $trashCondition = $trashed ? 'p.deletedAt IS NOT NULL' : 'p.deletedAt IS NULL';
        $queryBuilder->andWhere($trashCondition);
        $countQueryBuilder->andWhere($trashCondition);

        if (null !== $search && '' !== mb_trim($search)) {
            $rankedIds = $this->fullTextPostIds($search);
            $likeIds = $this->titleSlugMatchIds($search);
            $allIds = array_values(array_unique(array_merge($rankedIds, $likeIds)));

            if ([] === $allIds) {
                return ['items' => [], 'total' => 0, 'page' => max(1, $page), 'totalPages' => 1];
            }

            $queryBuilder->andWhere('p.id IN (:searchIds)')->setParameter('searchIds', $allIds);
            $countQueryBuilder->andWhere('p.id IN (:searchIds)')->setParameter('searchIds', $allIds);

            // Full-text ranked IDs first, then LIKE-only matches at the end
            if ([] !== $rankedIds) {
                $caseExpr = 'CASE p.id';
                foreach ($rankedIds as $index => $id) {
                    $caseExpr .= sprintf(' WHEN %d THEN %d', (int) $id, $index);
                }

                $caseExpr .= ' ELSE '.count($rankedIds).' END';
                $queryBuilder->resetDQLPart('orderBy')->orderBy($caseExpr, Order::Ascending->value);
            }
        }

        if ([] !== $postTypeIds) {
            $queryBuilder->andWhere('p.postType IN (:postTypeIds)')->setParameter('postTypeIds', $postTypeIds);
            $countQueryBuilder->andWhere('p.postType IN (:postTypeIds)')->setParameter('postTypeIds', $postTypeIds);
        }

        if ([] !== $statuses) {
            $queryBuilder->andWhere('p.status IN (:statuses)')->setParameter('statuses', $statuses);
            $countQueryBuilder->andWhere('p.status IN (:statuses)')->setParameter('statuses', $statuses);
        }

        if (null !== $authorId) {
            $authorCondition = 'p.author = :authorId';
            $queryBuilder->andWhere($authorCondition)->setParameter('authorId', $authorId);
            $countQueryBuilder->andWhere($authorCondition)->setParameter('authorId', $authorId);
        }

        if ([] !== $termIds) {
            $queryBuilder->innerJoin('p.terms', 'filterTerm')->andWhere('filterTerm.id IN (:termIds)')->setParameter('termIds', $termIds);
            $countQueryBuilder->innerJoin('p.terms', 'filterTerm')->andWhere('filterTerm.id IN (:termIds)')->setParameter('termIds', $termIds);
        }

        $result = $this->paginate($queryBuilder, $countQueryBuilder, $page, $limit);
        $this->hydratePostCollections($result['items']);

        return $result;
    }

    /**
     * Batch-loads terms and relatedPosts for a slice of posts so that
     * PostSerializer::serialize does not fire one query per post.
     *
     * @param list<Post> $posts
     */
    private function hydratePostCollections(array $posts): void
    {
        if ([] === $posts) {
            return;
        }

        $ids = array_map(static fn (Post $post): int => $post->getId(), $posts);

        $this->createQueryBuilder('p')
            ->leftJoin('p.terms', 'terms')
            ->addSelect('terms')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $this->createQueryBuilder('p')
            ->leftJoin('p.relatedPosts', 'rp')
            ->addSelect('rp')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<int> post IDs matching the full-text query, ordered by rank (best first)
     */
    public function fullTextPostIds(string $search, int $limit = 200): array
    {
        $sql = <<<'SQL'
                SELECT pt.post_id, MAX(ts_rank(to_tsvector('simple', coalesce(pt.search_content, '')), websearch_to_tsquery('simple', :q))) AS rank
                FROM core_post_translations pt
                WHERE to_tsvector('simple', coalesce(pt.search_content, '')) @@ websearch_to_tsquery('simple', :q)
                GROUP BY pt.post_id
                ORDER BY rank DESC
                LIMIT :max
            SQL;

        $connection = $this->getEntityManager()->getConnection();
        $rows = $connection->fetchAllAssociative($sql, [
            'q' => $search,
            'max' => $limit,
        ], [
            'q' => ParameterType::STRING,
            'max' => ParameterType::INTEGER,
        ]);

        return array_map(static fn (array $row): int => (int) $row['post_id'], $rows);
    }

    /**
     * Returns post IDs whose title or slug contains the search string (case-insensitive, any locale).
     * When the search looks like a URL path (contains "/"), the last non-empty path segment is used
     * so that typing "/fr/page/confidentialite" correctly finds the post with slug "confidentialite".
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

        $pattern = '%'.mb_strtolower(addcslashes($term, '%_\\')).'%';

        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.id AS post_id')
            ->innerJoin('p.translations', 'ts')
            ->andWhere('p.deletedAt IS NULL')
            ->andWhere('LOWER(ts.title) LIKE :pattern OR LOWER(ts.slug) LIKE :pattern')
            ->setParameter('pattern', $pattern)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['post_id'], $rows);
    }

    /**
     * @return list<Post>
     */
    public function findAllTrashed(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.deletedAt IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    public function findPurgeable(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.deletedAt IS NOT NULL')
            ->andWhere('p.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    public function findPublishedBySlug(string $slug, string $locale): ?Post
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
     * @return array{items: list<Post>, total: int, page: int, totalPages: int}
     */
    /**
     * @return array{items: list<Post>, total: int, page: int, totalPages: int}
     */
    public function findPublishedByPostTypeWithSearch(int $postTypeId, int $page, int $limit, string $locale, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->addSelect('t')
            ->andWhere('p.postType = :postTypeId')
            ->andWhere('p.status = :status')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('locale', $locale)
            ->setParameter('postTypeId', $postTypeId)
            ->setParameter('status', PostStatusEnum::Published)
            ->orderBy('p.publishedAt', Order::Descending->value)
            ->addOrderBy('p.createdAt', Order::Descending->value);

        $countQb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.postType = :postTypeId')
            ->andWhere('p.status = :status')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('postTypeId', $postTypeId)
            ->setParameter('status', PostStatusEnum::Published);

        if (null !== $search && '' !== mb_trim($search)) {
            $rankedIds = $this->fullTextPostIds($search);
            $likeIds = $this->titleSlugMatchIds($search);
            $allIds = array_values(array_unique(array_merge($rankedIds, $likeIds)));

            if ([] === $allIds) {
                return ['items' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1];
            }

            $qb->andWhere('p.id IN (:searchIds)')->setParameter('searchIds', $allIds);
            $countQb->andWhere('p.id IN (:searchIds)')->setParameter('searchIds', $allIds);
        }

        return $this->paginate($qb, $countQb, $page, $limit);
    }

    /**
     * @return array{items: list<Post>, total: int, page: int, totalPages: int}
     */
    public function findPublishedByTerm(int $termId, int $page, int $limit, string $locale): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->innerJoin('p.terms', 'term')
            ->addSelect('t')
            ->andWhere('term.id = :termId')
            ->andWhere('p.status = :status')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('locale', $locale)
            ->setParameter('termId', $termId)
            ->setParameter('status', PostStatusEnum::Published)
            ->orderBy('p.publishedAt', Order::Descending->value);

        $countQueryBuilder = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->innerJoin('p.terms', 'term')
            ->andWhere('term.id = :termId')
            ->andWhere('p.status = :status')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('termId', $termId)
            ->setParameter('status', PostStatusEnum::Published);

        return $this->paginate($queryBuilder, $countQueryBuilder, $page, $limit);
    }

    /**
     * @return list<Post>
     */
    public function findAllPublishedForSitemap(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't')
            ->leftJoin('p.postType', 'pt')
            ->addSelect('t', 'pt')
            ->andWhere('p.status = :status')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('status', PostStatusEnum::Published)
            ->orderBy('p.publishedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Post>
     */
    public function findScheduledDueBy(DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.scheduledAt IS NOT NULL')
            ->andWhere('p.scheduledAt <= :now')
            ->setParameter('status', PostStatusEnum::Scheduled)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    public function countTrashed(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.deletedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Counts posts created since the given date, grouped by YYYY-MM.
     *
     * @return array<string, int> map of 'YYYY-MM' => count
     */
    public function countByMonthSince(DateTimeImmutable $since): array
    {
        $sqlQuery = <<<'SQL'
                SELECT TO_CHAR(created_at, 'YYYY-MM') AS month, COUNT(*) AS count
                FROM core_posts
                WHERE created_at >= :since
                GROUP BY month
                ORDER BY month ASC
            SQL;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($sqlQuery, ['since' => $since->format('Y-m-d H:i:s')]);

        $monthCountMap = [];
        foreach ($rows as $row) {
            $monthCountMap[$row['month']] = (int) $row['count'];
        }

        return $monthCountMap;
    }

    /**
     * @return list<Post>
     */
    public function searchForReference(?string $query, ?int $excludeId = null, ?int $postTypeId = null, int $limit = 20): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't')
            ->leftJoin('p.postType', 'pt')
            ->addSelect('t', 'pt')
            ->where('p.deletedAt IS NULL')
            ->orderBy('p.updatedAt', Order::Descending->value)
            ->setMaxResults($limit);

        if (null !== $excludeId) {
            $queryBuilder->andWhere('p.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        if (null !== $postTypeId) {
            $queryBuilder->andWhere('p.postType = :postTypeId')->setParameter('postTypeId', $postTypeId);
        }

        if (null !== $query && '' !== $query) {
            $queryBuilder->andWhere('LOWER(t.title) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($query).'%');
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Post>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't')
            ->leftJoin('p.postType', 'pt')
            ->addSelect('t', 'pt')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns a map of postTypeId → count for all post types.
     * Single GROUP BY query replacing one count() call per type.
     *
     * @return array<int, int>
     */
    public function countGroupedByPostType(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.postType) AS typeId, COUNT(p.id) AS cnt')
            ->groupBy('p.postType')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'cnt', 'typeId');
    }

    /**
     * Returns a map of status → count for non-trashed posts.
     * Single GROUP BY query replacing five separate count() calls.
     *
     * @return array<string, int>
     */
    public function countGroupedByStatus(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.status, COUNT(p.id) AS cnt')
            ->where('p.deletedAt IS NULL')
            ->groupBy('p.status')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['status'] instanceof PostStatusEnum ? $row['status']->value : (string) $row['status']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * @return array<int, int> map of author_id => post count (non-trashed posts);
     *                         authorless posts are grouped under key 0
     */
    public function countGroupedByAuthor(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.author) AS authorId, COUNT(p.id) AS cnt')
            ->where('p.deletedAt IS NULL')
            ->groupBy('p.author')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['authorId']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * @return list<Post>
     */
    public function findRecent(int $limit = 5): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.postType', 'pt')
            ->leftJoin('p.translations', 't')
            ->addSelect('pt', 't')
            ->orderBy('p.updatedAt', Order::Descending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
