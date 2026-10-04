<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * Les livrables perso d'une personne, sans espace, le dernier touché en
     * premier.
     *
     * Un administrateur y trouve aussi les livrables perso restés sans auteur :
     * c'est lui qui les recueille, cf. {@see DeliverableAccess::adopts()}.
     *
     * @return list<DeliverableInterface>
     */
    public function findPersonalFor(CoreUserInterface $user): array
    {
        $builder = $this->standalone(DeliverableScopeEnum::Personal);

        if (DeliverableAccess::isAdmin($user)) {
            $builder->andWhere('d.owner = :owner OR d.owner IS NULL');
        } else {
            $builder->andWhere('d.owner = :owner');
        }

        return $builder->setParameter('owner', $user)->getQuery()->getResult();
    }

    /**
     * Les livrables partagés de Studio, ceux de toute l'équipe.
     *
     * @return list<DeliverableInterface>
     */
    public function findShared(): array
    {
        return $this->standalone(DeliverableScopeEnum::Shared)->getQuery()->getResult();
    }

    /** Un livrable sans espace : ceux d'un espace ne s'ouvrent que par lui. */
    public function findStandalone(int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.space IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function standalone(DeliverableScopeEnum $scope): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            // La catégorie de chaque carte, dans la même requête.
            ->leftJoin('d.category', 'c')
            ->addSelect('c')
            ->where('d.space IS NULL')
            ->andWhere('d.scope = :scope')
            ->setParameter('scope', $scope)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value);
    }
}
