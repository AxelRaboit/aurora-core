<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Form\Entity\FormTranslation;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<FormTranslationInterface>
 */
class FormTranslationRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormTranslation::class, FormTranslationInterface::class);
    }

    /**
     * What the public route resolves. Fields and their translations come
     * along: the page renders every one of them, and letting Doctrine fetch
     * them lazily is a query per field on a route open to the world.
     */
    public function findOneByLocaleAndSlug(string $locale, string $slug): ?FormTranslationInterface
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.form', 'f')
            ->leftJoin('f.fields', 'field')
            ->leftJoin('field.translations', 'ft')
            ->addSelect('f', 'field', 'ft')
            ->where('t.locale = :locale')
            ->andWhere('t.slug = :slug')
            ->setParameter('locale', $locale)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function isSlugTaken(string $locale, string $slug, ?int $exceptFormId = null): bool
    {
        $queryBuilder = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.locale = :locale')
            ->andWhere('t.slug = :slug')
            ->setParameter('locale', $locale)
            ->setParameter('slug', $slug);

        if (null !== $exceptFormId) {
            $queryBuilder->andWhere('IDENTITY(t.form) <> :formId')->setParameter('formId', $exceptFormId);
        }

        return (int) $queryBuilder->getQuery()->getSingleScalarResult() > 0;
    }
}
