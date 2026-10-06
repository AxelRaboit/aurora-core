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
     * Les ressources d'un espace, dans l'ordre où on les a rangées.
     *
     * `$visibleOnly` n'est pas un filtre d'affichage : c'est ce que la page du
     * client appelle, et une ressource fermée ne sort alors pas du serveur.
     * Le studio appelle la même méthode sans le drapeau, donc il n'y a qu'un
     * seul tri et qu'un seul endroit où se tromper.
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
     * La place suivante dans la liste d'un espace.
     *
     * Lue plutôt que comptée : compter les lignes donnerait la même valeur à
     * deux ajouts après une suppression, et deux ressources à la même place se
     * départageraient alors par leur identifiant, ce qui est un ordre mais pas
     * celui qu'on a choisi.
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
