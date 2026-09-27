<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<FormInterface>
 */
class FormRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Form::class, FormInterface::class);
    }

    /**
     * One form with everything a reader sees: its translations, its fields
     * and theirs. The same load as the form's own page, for a form drawn
     * inside another page.
     */
    public function findForReader(int $id): ?FormInterface
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.translations', 't')
            ->leftJoin('f.fields', 'field')
            ->leftJoin('field.translations', 'ft')
            ->addSelect('t', 'field', 'ft')
            ->where('f.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The id of the form the builder's list shows first, or null.
     *
     * The same order as `findAllForIndex()`, without loading every form with
     * its fields and translations to read one id.
     */
    public function firstId(): ?int
    {
        $id = $this->createQueryBuilder('f')
            ->select('f.id')
            ->orderBy('f.updatedAt', Order::Descending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return null === $id ? null : (int) $id;
    }

    /**
     * The builder's list, with everything it draws.
     *
     * @return list<FormInterface>
     */
    public function findAllForIndex(): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.translations', 't')
            ->leftJoin('f.fields', 'field')
            ->leftJoin('field.translations', 'ft')
            ->addSelect('t', 'field', 'ft')
            ->orderBy('f.updatedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }
}
