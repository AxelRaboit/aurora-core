<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<DeliverableInterface>
 */
class DeliverableRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deliverable::class, DeliverableInterface::class);
    }

    /**
     * Les livrables d'un espace, le dernier touché en premier.
     *
     * `$visibleOnly` est ce que la page du client appelle : un livrable fermé
     * ne sort pas du serveur, plutôt que d'être caché à l'affichage. Le studio
     * appelle la même méthode sans le drapeau, d'où un seul tri.
     *
     * @return list<DeliverableInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space, bool $visibleOnly = false): array
    {
        $builder = $this->createQueryBuilder('d')
            ->where('d.space = :space')
            ->setParameter('space', $space)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value);

        if ($visibleOnly) {
            $builder->andWhere('d.visibleToClient = true');
        }

        return $builder->getQuery()->getResult();
    }

    /** Celui-ci, à condition qu'il appartienne à cet espace : une adresse ne franchit pas un espace. */
    public function findInSpace(CustomerSpaceInterface $space, int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.space = :space')
            ->setParameter('id', $id)
            ->setParameter('space', $space)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
