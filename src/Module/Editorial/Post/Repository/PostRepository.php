<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Service\PostPictures;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function array_fill_keys;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function sprintf;

/**
 * @extends ResolveTargetEntityRepository<PostInterface>
 */
class PostRepository extends ResolveTargetEntityRepository
{
    use PaginationTrait;

    /**
     * {@see PostPictures} is injected rather than built here so the walk over
     * a post's picture slots has one owner: the usage lookup below confirms
     * its candidates with it, and so does everything that later needs to know
     * what a post draws.
     */
    public function __construct(ManagerRegistry $registry, private readonly PostPictures $pictures)
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

    /**
     * A published publication at this address.
     *
     * The type narrows it when the caller knows which one the address named,
     * and it always does on the public route - `/{locale}/{type}/{slug}`. An
     * address is only unique inside its type: a documentation page and a card
     * of the tour may both be called "tableau-de-bord", and looking one up by
     * slug alone answers with whichever the database returns first.
     *
     * Left optional because the same lookup, without the type, is what tells
     * the controller that a publication has changed type since the address was
     * shared - which is the one case that must still redirect rather than 404.
     */
    public function findPublishedBySlug(string $slug, string $locale, ?int $postTypeId = null): ?PostInterface
    {
        $query = $this->createQueryBuilder('p')
            ->innerJoin('p.translations', 't')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.slug = :slug')
            ->andWhere('p.status = :status')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('locale', $locale)
            ->setParameter('slug', $slug)
            ->setParameter('status', PostStatusEnum::Published)
            ->setMaxResults(1);

        if (null !== $postTypeId) {
            $query->andWhere('p.postType = :postType')->setParameter('postType', $postTypeId);
        }

        return $query->getQuery()->getOneOrNullResult();
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
            ->addOrderBy('p.id', Order::Descending->value);
        $this->readingOrder($items);

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
        return $this->findPublishedByTerms([$termId], $page, $limit, $locale);
    }

    /**
     * The publications of several terms at once, without duplicates.
     *
     * What a term page of a hierarchical taxonomy asks for: a publication is
     * filed under a leaf, so a section holding only sub-sections holds no
     * publication of its own. Listing the term alone answered an empty page
     * at an address that is legitimately reachable.
     *
     * Membership is asked as an EXISTS rather than as a join, and that is not
     * a style choice: a publication carrying two terms of the same branch
     * matches the join twice, so it would be printed twice and counted twice.
     * `DISTINCT` would be the usual answer and PostgreSQL refuses it here -
     * the row carries the grid, a `json` column, and json has no equality
     * operator. Not joining at all settles both.
     *
     * @param list<int> $termIds
     *
     * @return array{items: list<PostInterface>, total: int, page: int, totalPages: int}
     */
    public function findPublishedByTerms(array $termIds, int $page, int $limit, string $locale): array
    {
        if ([] === $termIds) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1];
        }

        $inBranch = sprintf(
            'SELECT 1 FROM %s branchPost JOIN branchPost.terms branchTerm WHERE branchPost = p AND branchTerm.id IN (:termIds)',
            $this->getEntityName(),
        );

        $items = $this->publishedQueryBuilder($locale)
            ->addSelect('t')
            ->andWhere(sprintf('EXISTS (%s)', $inBranch))
            ->setParameter('termIds', $termIds)
            ->addOrderBy('p.id', Order::Descending->value);
        $this->readingOrder($items);

        $count = $this->publishedQueryBuilder($locale)
            ->select('COUNT(p.id)')
            ->andWhere(sprintf('EXISTS (%s)', $inBranch))
            ->setParameter('termIds', $termIds);

        return $this->paginate($items, $count, $page, $limit);
    }

    /**
     * The shape every public listing starts from. An INNER JOIN on the
     * translation is what drops posts untranslated in this locale.
     */
    /**
     * The newest published posts, for a list zone that keeps itself current.
     *
     * Both filters are optional and combine: a type alone, a term alone, both
     * together, or neither for the whole site. Unpaginated on purpose - a
     * zone shows a handful and the cap is the author's, not a page number.
     *
     * `$exclude` is the page doing the asking: a publication that lists its
     * neighbours should not offer itself among them.
     *
     * @return list<PostInterface>
     */
    public function findLatestPublished(
        string $locale,
        int $limit,
        ?int $postTypeId = null,
        ?int $termId = null,
        ?int $exclude = null,
    ): array {
        $query = $this->publishedQueryBuilder($locale)
            ->addSelect('t')
            ->addOrderBy('p.id', Order::Descending->value)
            ->setMaxResults(max(1, $limit));

        $this->readingOrder($query);

        if (null !== $postTypeId) {
            $query->andWhere('p.postType = :postType')->setParameter('postType', $postTypeId);
        }

        if (null !== $termId) {
            $query->innerJoin('p.terms', 'term')
                ->andWhere('term.id = :termId')
                ->setParameter('termId', $termId);
        }

        if (null !== $exclude) {
            $query->andWhere('p.id != :exclude')->setParameter('exclude', $exclude);
        }

        /** @var list<PostInterface> $posts */
        $posts = $query->getQuery()->getResult();

        return $posts;
    }

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
     * Published posts whose end date has passed.
     *
     * The mirror of `findDueForPublication`, and it asks for `published`
     * specifically: a draft carrying an old end date is not something to act on,
     * and archiving it would move a post the author is still working on.
     *
     * Trashed rows are out for the same reason they are out of every other listing.
     *
     * @return list<PostInterface>
     */
    public function findDueForUnpublishing(DateTimeImmutable $now): array
    {
        /** @var list<PostInterface> $result */
        $result = $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.unpublishAt IS NOT NULL')
            ->andWhere('p.unpublishAt <= :now')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('status', PostStatusEnum::Published)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        return $result;
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
     * Published publications, for a picker that has to name one.
     *
     * Published only, and deliberately: an unpublished publication is a draft,
     * the archive refuses to borrow a draft's header at render, and offering
     * one here would be offering a choice that silently does nothing.
     *
     * @return list<PostInterface>
     */
    public function findAllPublishedForPicker(): array
    {
        /** @var list<PostInterface> $posts */
        $posts = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't')
            ->addSelect('t')
            ->where('p.status = :status')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('status', PostStatusEnum::Published)
            ->orderBy('p.publishedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();

        return $posts;
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

    /**
     * When the oldest row now in the trash was deleted, null when it is empty.
     *
     * Read by the trash overview to say how long is left before the purge
     * takes it. A date rather than a row: the overview shows neither.
     */
    public function oldestTrashedAt(): ?DateTimeImmutable
    {
        $value = $this->createQueryBuilder('p')
            ->select('MIN(p.deletedAt)')
            ->andWhere('p.deletedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : new DateTimeImmutable((string) $value);
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
    /**
     * The order a listing is read in: numbered first, then newest.
     *
     * A publication carries an optional position, and null means "no opinion".
     * The clause replaces whatever ordering the caller set, and a listing where
     * nobody numbered anything sorts exactly as it did before this existed -
     * every position being null, the date decides.
     *
     * **It leans on PostgreSQL sorting nulls last in ascending order**, which
     * is what makes an unnumbered publication fall in behind a numbered one
     * rather than ahead of it. That is a default, not a guarantee of the
     * standard, and the database is PostgreSQL everywhere this runs - so the
     * behaviour is pinned by a test rather than by a `COALESCE` nobody would
     * understand two years from now.
     */
    private function readingOrder(QueryBuilder $queryBuilder): void
    {
        $queryBuilder
            ->orderBy('p.position', Order::Ascending->value)
            ->addOrderBy('p.publishedAt', Order::Descending->value)
            ->addOrderBy('p.id', Order::Descending->value);
    }

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

    /**
     * The posts that point at one document, whichever of the six ways.
     *
     * A post reaches a GED document six times over: its own cover
     * (`thumbnail`), the social image of each translation (`ogImage`), the
     * pictures of its gallery, the logo, backdrop and pictures of its banner,
     * and the images placed in its content grid. The first two are typed FKs,
     * the rest are ids inside JSON. All of them are answered here so the
     * library reports the post once, not six times, when several of them name
     * the same file.
     *
     * **Three columns, not one.** Until 2026-09-25 only `galleryLayout` was
     * read, so a picture placed in a page or in its banner - which is where
     * nearly every picture on this site lives - came back as used by nobody.
     * Measured that day against the production data: of the 163 documents a
     * post was drawing, this found 73.
     *
     * The JSON is narrowed in SQL before it is verified in PHP, in two steps
     * rather than one clause. Decks can be walked because a project holds
     * dozens; posts run to thousands, and loading every one to look inside a
     * JSON column is not a query to run on a click. It cannot be a `LIKE` in
     * the DQL either: Postgres has no `~~` for `json`, so the cast is explicit
     * and the narrowing is a native query - the same trade
     * {@see self::fullTextPostIds()} already makes.
     *
     * The narrowing matches the id as a whole word behind a key that carries
     * one, `\m` and `\M` being Postgres' word boundaries, so asking for 123
     * no longer drags in every post that mentions 1234. It stays a narrowing
     * all the same, since a bracketed list is matched by its key and not by
     * its position, and {@see PostPictures} is what makes the answer exact.
     *
     * @return list<PostInterface>
     */
    public function findUsingDocument(int $documentId): array
    {
        $candidates = $this->postIdsMentioning([$documentId]);

        $builder = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't')
            ->where('p.thumbnail = :document')
            ->orWhere('t.ogImage = :document')
            ->setParameter('document', $documentId)
            ->orderBy('p.id', Order::Ascending->value);

        if ([] !== $candidates) {
            $builder
                ->orWhere('p.id IN (:candidates)')
                ->setParameter('candidates', $candidates);
        }

        /** @var list<PostInterface> $posts */
        $posts = $builder->getQuery()->getResult();

        $pictures = $this->pictures;

        return array_values(array_filter(
            $posts,
            static fn (PostInterface $post): bool => $pictures->uses($post, $documentId),
        ));
    }

    /**
     * How many posts draw each of these documents, in one pass.
     *
     * {@see self::findUsingDocument()} answers about one picture, which is
     * what the deletion screen asks. The library's listing asks about the
     * fifty on the page, and calling that method fifty times would run fifty
     * narrowings and load the candidates fifty times over.
     *
     * So the narrowing takes every id at once - one alternation, one scan -
     * and the candidates are walked a single time, each post's pictures
     * tallied against the ids that were asked for. A post drawing the same
     * picture in its banner and its grid counts once, as it does there.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countUsagesByDocument(array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }

        $wanted = array_fill_keys($documentIds, true);
        $candidates = $this->postIdsMentioning($documentIds);

        $builder = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't')
            ->where('p.thumbnail IN (:documents)')
            ->orWhere('t.ogImage IN (:documents)')
            ->setParameter('documents', $documentIds)
            ->orderBy('p.id', Order::Ascending->value);

        if ([] !== $candidates) {
            $builder->orWhere('p.id IN (:candidates)')->setParameter('candidates', $candidates);
        }

        /** @var list<PostInterface> $posts */
        $posts = $builder->getQuery()->getResult();

        $counts = [];

        foreach ($posts as $post) {
            foreach ($this->pictures->idsUsedBy($post) as $documentId) {
                if (isset($wanted[$documentId])) {
                    $counts[$documentId] = ($counts[$documentId] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * The posts whose JSON columns name any of these documents - the
     * narrowing both usage lookups start from, one scan whatever the count.
     *
     * Four columns, and the fourth is on another table: a translation may put
     * a banner background of its own behind its page, for a picture with words
     * in it. Leaving it out would answer "used by nobody" for a picture only
     * the Spanish page shows.
     *
     * @param list<int> $documentIds
     *
     * @return list<int>
     */
    private function postIdsMentioning(array $documentIds): array
    {
        $metadata = $this->getClassMetadata();
        $entityManager = $this->getEntityManager();
        $translation = $entityManager->getClassMetadata($metadata->getAssociationTargetClass('translations'));

        $conditions = array_map(
            static fn (string $column): string => sprintf('%s::text ~ :pattern', $column),
            array_map($metadata->getColumnName(...), ['galleryLayout', 'bannerLayout', 'gridLayout']),
        );

        $conditions[] = sprintf(
            'id IN (SELECT %s FROM %s WHERE %s::text ~ :pattern)',
            $translation->getSingleAssociationJoinColumnName('post'),
            $translation->getTableName(),
            $translation->getColumnName('banner'),
        );

        $rows = $entityManager->getConnection()->fetchFirstColumn(
            sprintf('SELECT id FROM %s WHERE %s', $metadata->getTableName(), implode(' OR ', $conditions)),
            ['pattern' => sprintf(
                '"(mediaId|mediaIds|logoMediaId|mobileMediaId)":\s*\[?[\s0-9,]*\m(%s)\M',
                implode('|', array_map(static fn (int $id): string => (string) $id, $documentIds)),
            )],
            ['pattern' => ParameterType::STRING],
        );

        return array_map(static fn (mixed $id): int => (int) $id, $rows);
    }
}
