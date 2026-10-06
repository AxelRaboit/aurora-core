<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResource;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResourceInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceResourceInterface>
 */
class SpaceResourceRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceResource::class, SpaceResourceInterface::class);
    }

    /**
     * The resources of a space, in the order they were arranged.
     *
     * `$visibleOnly` is not a display filter: it is what the client's page
     * calls, and a closed resource then does not leave the server. The studio
     * calls the same method without the flag, so there is only one sort and
     * only one place to get it wrong.
     *
     * @return list<SpaceResourceInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space, bool $visibleOnly = false): array
    {
        $builder = $this->createQueryBuilder('r')
            ->where('r.space = :space')
            ->setParameter('space', $space);

        if ($visibleOnly) {
            $builder->andWhere('r.visibleToClient = true');
        }

        return $builder
            ->orderBy('r.position', Order::Ascending->value)
            ->addOrderBy('r.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The next place in the list of a space.
     *
     * Read rather than counted: counting the rows would give the same value to
     * two additions after a deletion, and two resources in the same place would
     * then be ordered by their identifier, which is an order but not the one
     * that was chosen.
     */
    public function nextPosition(CustomerSpaceInterface $space): int
    {
        $highest = $this->createQueryBuilder('r')
            ->select('MAX(r.position)')
            ->where('r.space = :space')
            ->setParameter('space', $space)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : ((int) $highest) + 1;
    }

    /**
     * The resources whose label, body or address contains the term, for the
     * global search.
     *
     * `$spaceIds` null means every space (a reader who sees all of them); a
     * list narrows the search to those spaces, and an empty list finds nothing.
     * A space in the trash is never searched, like its screens. The space comes
     * along with each row: the result names it.
     *
     * @param list<int>|null $spaceIds
     *
     * @return list<SpaceResourceInterface>
     */
    public function search(string $term, ?array $spaceIds, int $limit): array
    {
        if ('' === mb_trim($term) || [] === $spaceIds) {
            return [];
        }

        $builder = $this->createQueryBuilder('r')
            ->addSelect('s')
            ->join('r.space', 's')
            ->where('LOWER(r.label) LIKE :term OR LOWER(r.body) LIKE :term OR LOWER(r.url) LIKE :term')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('r.updatedAt', Order::Descending->value)
            ->addOrderBy('r.id', Order::Descending->value)
            ->setMaxResults($limit);

        if (null !== $spaceIds) {
            $builder->andWhere('s.id IN (:ids)')->setParameter('ids', $spaceIds);
        }

        return $builder->getQuery()->getResult();
    }
}
