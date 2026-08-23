<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<TaxonomyInterface>
 */
class TaxonomyRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Taxonomy::class, TaxonomyInterface::class);
    }

    public function findOneBySlug(string $slug): ?TaxonomyInterface
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Enough to serialize a taxonomy without its terms - the shape the
     * post-types screen needs when it lists which taxonomies apply.
     *
     * @return list<TaxonomyInterface>
     */
    public function findAllWithTranslations(): array
    {
        return $this->createQueryBuilder('tx')
            ->leftJoin('tx.translations', 'trt')
            ->leftJoin('tx.postTypes', 'pt')
            ->addSelect('trt', 'pt')
            ->orderBy('tx.slug', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything the taxonomies screen draws, in one query: translations,
     * applicable post types, terms and their translations. Without the
     * joins this is a query per association per taxonomy.
     *
     * @return list<TaxonomyInterface>
     */
    public function findAllForIndex(): array
    {
        return $this->createQueryBuilder('tx')
            ->leftJoin('tx.translations', 'trt')
            ->leftJoin('tx.postTypes', 'pt')
            ->leftJoin('tx.terms', 'term')
            ->leftJoin('term.translations', 'tmt')
            ->addSelect('trt', 'pt', 'term', 'tmt')
            ->orderBy('tx.slug', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }
}
