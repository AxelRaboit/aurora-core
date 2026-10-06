<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function array_map;

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
            ->leftJoin('d.thumbnail', 't')
            ->addSelect('t')
            ->where('d.space = :space')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('space', $space)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value);

        if ($visibleOnly) {
            $builder->andWhere('d.visibleToClient = true');
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * Les lignes de la liste d'un espace, sans leur corps.
     *
     * Un livrable porte sa grille et son contenu en JSON, jusqu'à une centaine
     * de kilo-octets pour un audit complet ; la page de l'espace n'en affiche
     * que le titre, l'état et l'image. Lire les colonnes seules évite
     * d'hydrater et de décoder tous les corps à chaque ouverture d'un onglet
     * qui n'est même pas celui des livrables. Le même tri que
     * {@see self::findForSpace()}.
     *
     * @return list<array{id: int, title: string, summary: ?string, format: string, visibleToClient: bool, updatedAt: DateTimeImmutable, thumbnailId: ?int}>
     */
    public function findRowsForSpace(CustomerSpaceInterface $space, bool $visibleOnly = false): array
    {
        $builder = $this->createQueryBuilder('d')
            ->select('d.id AS id, d.title AS title, d.summary AS summary, d.format AS format, d.visibleToClient AS visibleToClient, d.updatedAt AS updatedAt, IDENTITY(d.thumbnail) AS thumbnailId')
            ->where('d.space = :space')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('space', $space)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value);

        if ($visibleOnly) {
            $builder->andWhere('d.visibleToClient = true');
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'summary' => null === $row['summary'] ? null : (string) $row['summary'],
            'format' => $row['format'] instanceof DeliverableFormatEnum ? $row['format']->value : (string) $row['format'],
            'visibleToClient' => (bool) $row['visibleToClient'],
            'updatedAt' => $row['updatedAt'],
            'thumbnailId' => null === $row['thumbnailId'] ? null : (int) $row['thumbnailId'],
        ], $builder->getQuery()->getArrayResult());
    }

    /** Celui-ci, à condition qu'il appartienne à cet espace : une adresse ne franchit pas un espace. */
    public function findInSpace(CustomerSpaceInterface $space, int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.space = :space')
            ->andWhere('d.deletedAt IS NULL')
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

    /**
     * Les livrables dont le titre contient ce terme, les plus récents d'abord :
     * des candidats pour la recherche globale, que l'appelant filtre par ce que
     * la personne a le droit de lire, ce que le SQL ne sait pas dire.
     *
     * @return list<DeliverableInterface>
     */
    public function searchByTitle(string $term, int $limit): array
    {
        if ('' === mb_trim($term)) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->leftJoin('d.space', 's')
            ->addSelect('s')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            ->where('LOWER(d.title) LIKE :term')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Un livrable vivant, de Studio ou d'un espace : celui qu'on ouvre, qu'on
     * modifie, qu'on envoie. Un livrable à la corbeille n'est plus là pour
     * personne, et répond comme un identifiant inconnu.
     */
    public function findLive(int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Un livrable à la corbeille, de Studio ou d'un espace : ce qu'on restaure ou détruit pour de bon. */
    public function findTrashed(int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.deletedAt IS NOT NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Tout ce que la corbeille contient, le dernier arrivé en premier. L'appelant
     * garde ce que la personne a le droit de voir : le SQL ne sait pas dire qui
     * lit un livrable perso ou celui d'un espace.
     *
     * @return list<DeliverableInterface>
     */
    public function findAllTrashed(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.space', 's')
            ->addSelect('s')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            ->where('d.deletedAt IS NOT NULL')
            ->orderBy('d.deletedAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Ceux qui sont à la corbeille depuis avant cette date : la purge
     * planifiée les détruit.
     *
     * @return list<DeliverableInterface>
     */
    public function findTrashedBefore(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.deletedAt IS NOT NULL')
            ->andWhere('d.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les livrables, de Studio et d'espaces, avec leur image : de quoi
     * dire quels documents de la médiathèque ils affichent.
     *
     * Parcourus et non joints : un livrable garde ses images en identifiants
     * dans son JSON, comme une diapositive. Sans ordre, c'est un décompte.
     *
     * @return list<DeliverableInterface>
     */
    public function findAllForUsage(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.thumbnail', 't')
            ->addSelect('t')
            ->leftJoin('d.space', 's')
            ->addSelect('s')
            // Les diapositives d'un diaporama portent ses images : chargées
            // avec lui, plutôt qu'une requête par livrable.
            ->leftJoin('d.slides', 'sl')
            ->addSelect('sl')
            ->getQuery()
            ->getResult();
    }

    /**
     * Combien de livrables de Studio cette personne voit : les partagés, et
     * ses propres livrables perso (ceux sans auteur aussi, pour qui les
     * recueille). Un décompte, pour le tableau de bord, sans rien hydrater.
     */
    public function countStandaloneFor(CoreUserInterface $user): int
    {
        $builder = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.space IS NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.scope = :shared OR d.owner = :owner'.(DeliverableAccess::isAdmin($user) ? ' OR d.owner IS NULL' : ''))
            ->setParameter('shared', DeliverableScopeEnum::Shared)
            ->setParameter('owner', $user);

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * Les livrables de Studio écrits pour ce client, vivants, le dernier
     * touché en premier : la proposition faite avant que son espace existe.
     * Ceux de son espace ne comptent pas ici, l'espace les liste lui-même.
     *
     * Tous rayons confondus : l'appelant garde ce que la personne a le droit
     * de lire, ce que le SQL ne sait pas dire.
     *
     * @return list<DeliverableInterface>
     */
    public function findLiveStandaloneForCustomer(CustomerInterface $customer): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            ->where('d.space IS NULL')
            ->andWhere('d.customer = :customer')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('customer', $customer)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /** Un livrable sans espace : ceux d'un espace ne s'ouvrent que par lui. */
    public function findStandalone(int $id): ?DeliverableInterface
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.space IS NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function standalone(DeliverableScopeEnum $scope): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.owner', 'o')
            ->addSelect('o')
            // La catégorie, le client et l'image de chaque carte, dans la même requête.
            ->leftJoin('d.category', 'c')
            ->addSelect('c')
            ->leftJoin('d.customer', 'cu')
            ->addSelect('cu')
            ->leftJoin('d.thumbnail', 't')
            ->addSelect('t')
            ->where('d.space IS NULL')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.scope = :scope')
            ->setParameter('scope', $scope)
            ->orderBy('d.updatedAt', Order::Descending->value)
            ->addOrderBy('d.id', Order::Descending->value);
    }
}
