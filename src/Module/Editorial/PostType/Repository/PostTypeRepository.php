<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PostTypeInterface>
 */
class PostTypeRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostType::class, PostTypeInterface::class);
    }

    /**
     * Loads every post type with its custom fields in one go, so serializing
     * the list does not fire a query per collection.
     *
     * @return list<PostTypeInterface>
     */
    public function findAllWithRelations(): array
    {
        return $this->createQueryBuilder('pt')
            ->leftJoin('pt.fields', 'f')
            ->addSelect('f')
            ->orderBy('pt.label', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many posts each type holds, keyed by type id, in one query.
     *
     * The same rows `getPosts()->count()` counted, without loading them: the
     * back-office menu asked it of every type with no description, on every
     * Editorial page, and hydrated every post of the type to answer.
     *
     * @return array<int, int>
     */
    public function countPostsByType(): array
    {
        $rows = $this->createQueryBuilder('pt')
            ->select('pt.id AS id', 'COUNT(p.id) AS posts')
            ->leftJoin('pt.posts', 'p')
            ->groupBy('pt.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['posts'];
        }

        return $counts;
    }

    public function findOneBySlug(string $slug): ?PostTypeInterface
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
