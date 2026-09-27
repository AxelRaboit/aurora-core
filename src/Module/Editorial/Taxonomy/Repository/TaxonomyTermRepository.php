<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<TaxonomyTermInterface>
 */
class TaxonomyTermRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxonomyTerm::class, TaxonomyTermInterface::class);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<TaxonomyTermInterface>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Terms with what a menu or a card reads off them - their translations and
     * their taxonomy - in one query. Loaded plainly, each term cost one query
     * for its translations and one per taxonomy, on every page a menu shows.
     *
     * @param list<int> $ids
     *
     * @return list<TaxonomyTermInterface>
     */
    public function findForDisplay(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->leftJoin('t.translations', 'tt')
            ->innerJoin('t.taxonomy', 'tx')
            ->addSelect('tt', 'tx')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * The term of a taxonomy whose slug, in this locale, is this one.
     *
     * One query, where the page used to walk every term of the taxonomy and
     * load each one's translations to compare slugs - on any address of the
     * form `/{locale}/{a}/{b}` that found no publication, 404s included.
     */
    public function findOneBySlug(TaxonomyInterface $taxonomy, string $slug, string $locale): ?TaxonomyTermInterface
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.translations', 'match', 'WITH', 'match.locale = :locale AND match.slug = :slug')
            ->where('t.taxonomy = :taxonomy')
            ->setParameter('taxonomy', $taxonomy)
            ->setParameter('locale', $locale)
            ->setParameter('slug', $slug)
            ->orderBy('t.id', Order::Ascending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The ids of a term and of every term under it, at any depth.
     *
     * The taxonomy's parent links read in one scalar query and walked in
     * memory, like the sequence summary builds its tree. Walking the entities
     * instead asked for the children of every node, leaves included.
     *
     * @return list<int>
     */
    public function findSelfAndDescendantIds(TaxonomyTermInterface $term): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.id AS id', 'IDENTITY(t.parent) AS parentId')
            ->where('t.taxonomy = :taxonomy')
            ->setParameter('taxonomy', $term->getTaxonomy())
            ->getQuery()
            ->getArrayResult();

        $children = [];
        foreach ($rows as $row) {
            if (null !== $row['parentId']) {
                $children[(int) $row['parentId']][] = (int) $row['id'];
            }
        }

        $ids = [];
        $queue = [(int) $term->getId()];
        while ([] !== $queue) {
            $id = array_shift($queue);
            // A cycle cannot be saved, but a walk over data should not trust
            // that to terminate.
            if (in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
            $queue = [...$queue, ...$children[$id] ?? []];
        }

        return $ids;
    }

    /**
     * The same list with the translations, for a page that prints every name.
     *
     * @return list<TaxonomyTermInterface>
     */
    public function findByTaxonomyOrderedForDisplay(TaxonomyInterface $taxonomy): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.translations', 'tt')
            ->addSelect('tt')
            ->where('t.taxonomy = :taxonomy')
            ->setParameter('taxonomy', $taxonomy)
            ->orderBy('t.position', Order::Ascending->value)
            ->addOrderBy('t.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /** @return list<TaxonomyTermInterface> */
    public function findByTaxonomyOrdered(TaxonomyInterface $taxonomy): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.taxonomy = :taxonomy')
            ->setParameter('taxonomy', $taxonomy)
            ->orderBy('t.position', Order::Ascending->value)
            ->addOrderBy('t.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }
}
