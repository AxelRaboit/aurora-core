<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Doctrine\Common\Collections\Order;
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
