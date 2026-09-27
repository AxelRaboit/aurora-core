<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Module\Editorial\Comment\Entity\Comment;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<CommentInterface>
 */
class CommentRepository extends ResolveTargetEntityRepository
{
    use PaginationTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class, CommentInterface::class);
    }

    /**
     * The moderation queue.
     *
     * @return array{items: list<CommentInterface>, total: int, page: int, totalPages: int}
     */
    public function findPaginatedForAdmin(int $page, int $limit, ?CommentStatusEnum $status, ?string $search = null): array
    {
        // Only relations to one row each under the LIMIT. The post's
        // translations used to be joined here too, and a joined collection
        // multiplies the rows the LIMIT counts: in three languages a "page of
        // 20" held about seven comments, and some fell on no page at all.
        // The titles are loaded after the page, by warmPostTitles().
        $items = $this->createQueryBuilder('c')
            ->leftJoin('c.post', 'p')
            ->leftJoin('c.parent', 'parent')
            ->addSelect('p', 'parent')
            ->orderBy('c.createdAt', Order::Descending->value)
            ->addOrderBy('c.id', Order::Descending->value);

        $count = $this->createQueryBuilder('c')->select('COUNT(c.id)');

        if ($status instanceof CommentStatusEnum) {
            foreach ([$items, $count] as $queryBuilder) {
                $queryBuilder->andWhere('c.status = :status')->setParameter('status', $status);
            }
        }

        if (null !== $search && '' !== mb_trim($search)) {
            // Author and body, because a moderator hunts for either "who keeps
            // posting this" or "what was that link".
            foreach ([$items, $count] as $queryBuilder) {
                $queryBuilder
                    ->andWhere('LOWER(c.authorName) LIKE :search OR LOWER(c.authorEmail) LIKE :search OR LOWER(c.content) LIKE :search')
                    ->setParameter('search', '%'.mb_strtolower(mb_trim($search)).'%');
            }
        }

        $result = $this->paginate($items, $count, $page, $limit);
        $this->warmPostTitles($result['items']);

        return $result;
    }

    /**
     * How many replies each of these comments has, in one query.
     *
     * Keyed by id, every id present, zero included.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function countRepliesByComments(array $ids): array
    {
        $counts = array_fill_keys($ids, 0);
        if ([] === $ids) {
            return $counts;
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.parent) AS parentId', 'COUNT(c.id) AS replies')
            ->where('c.parent IN (:ids)')
            ->setParameter('ids', $ids)
            ->groupBy('c.parent')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $counts[(int) $row['parentId']] = (int) $row['replies'];
        }

        return $counts;
    }

    /**
     * The translations of the posts these comments are on, loaded in place.
     *
     * @param list<CommentInterface> $comments
     */
    private function warmPostTitles(array $comments): void
    {
        $posts = [];
        foreach ($comments as $comment) {
            $posts[(int) $comment->getPost()->getId()] = $comment->getPost();
        }

        $posts = array_values($posts);

        if ([] === $posts) {
            return;
        }

        $this->getEntityManager()->createQueryBuilder()
            ->select('p', 't')
            ->from($this->getClassMetadata()->getAssociationTargetClass('post'), 'p')
            ->leftJoin('p.translations', 't')
            ->where('p IN (:posts)')
            ->setParameter('posts', $posts)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every approved comment on a post - roots and replies at any depth - in
     * the order they were written. The tree is assembled in PHP: a thread is
     * small, and one query beats a recursive walk.
     *
     * @return list<CommentInterface>
     */
    public function findApprovedByPost(int $postId): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.parent', 'parent')
            ->addSelect('parent')
            ->where('c.post = :postId')
            ->andWhere('c.status = :status')
            ->setParameter('postId', $postId)
            ->setParameter('status', CommentStatusEnum::Approved)
            ->orderBy('c.createdAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, int> status value → count */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.status AS status', 'COUNT(c.id) AS total')
            ->groupBy('c.status')
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys(CommentStatusEnum::values(), 0);
        foreach ($rows as $row) {
            $status = $row['status'];
            $value = $status instanceof CommentStatusEnum ? $status->value : (string) $status;
            if (array_key_exists($value, $counts)) {
                $counts[$value] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function countApprovedByPost(int $postId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.post = :postId')
            ->andWhere('c.status = :status')
            ->setParameter('postId', $postId)
            ->setParameter('status', CommentStatusEnum::Approved)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many comments this fingerprint has left in the given window -
     * the flood check the public endpoint runs before writing anything.
     */
    public function countRecentByEmail(string $email, DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('LOWER(c.authorEmail) = :email')
            ->andWhere('c.createdAt >= :since')
            ->setParameter('email', mb_strtolower($email))
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
