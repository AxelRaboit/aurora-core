<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMember;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function sprintf;
use function str_contains;

/**
 * @extends ResolveTargetEntityRepository<NoteSpaceInterface>
 */
class NoteSpaceRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteSpace::class, NoteSpaceInterface::class);
    }

    /**
     * Les espaces qu'une personne peut lire, en une sous-requête DQL.
     *
     * **Une seule définition de « qui voit quoi »**, que toutes les requêtes
     * du module reprennent : son espace personnel, ceux dont elle est
     * propriétaire, ceux où elle est inscrite quand l'accès est aux membres,
     * et ceux ouverts à tout le back-office. Chaque liste qui réécrivait sa
     * propre règle aurait fini par en oublier une branche, et c'est comme ça
     * qu'une note s'ouvre par erreur.
     *
     * Le paramètre `:spaceViewer` et les trois paramètres d'accès sont posés
     * par {@see self::bindViewer()}.
     */
    public static function readableSubquery(string $prefix = 'rs'): string
    {
        return sprintf(
            'SELECT %1$s.id FROM %2$s %1$s LEFT JOIN %1$s.members %1$sm WITH %1$sm.user = :spaceViewer '
            .'WHERE %1$s.deletedAt IS NULL AND ('
            .'%1$s.personalUser = :spaceViewer OR %1$s.owner = :spaceViewer '
            .'OR %1$s.access = :spaceAccessBackoffice '
            .'OR (%1$s.access = :spaceAccessMembers AND %1$sm.id IS NOT NULL))',
            $prefix,
            NoteSpace::class,
        );
    }

    /**
     * Les espaces où une personne peut écrire : pareil, avec le rôle.
     *
     * Rédacteur ou gestionnaire par son inscription, ou par le rôle par défaut
     * d'un espace ouvert à tout le back-office.
     */
    public static function writableSubquery(string $prefix = 'ws'): string
    {
        return sprintf(
            'SELECT %1$s.id FROM %2$s %1$s LEFT JOIN %1$s.members %1$sm WITH %1$sm.user = :spaceViewer '
            .'WHERE %1$s.deletedAt IS NULL AND ('
            .'%1$s.personalUser = :spaceViewer OR %1$s.owner = :spaceViewer '
            .'OR (%1$s.access = :spaceAccessBackoffice AND (%1$s.defaultRole IN (:spaceWriterRoles) OR %1$sm.role IN (:spaceWriterRoles))) '
            .'OR (%1$s.access = :spaceAccessMembers AND %1$sm.role IN (:spaceWriterRoles)))',
            $prefix,
            NoteSpace::class,
        );
    }

    /**
     * Pose les paramètres des deux sous-requêtes sur une requête.
     *
     * Seulement ceux que la requête cite : Doctrine refuse un paramètre en
     * trop, et une requête de lecture ne cite pas les rôles d'écriture.
     */
    public static function bindViewer(QueryBuilder $qb, CoreUserInterface $user): QueryBuilder
    {
        $qb
            ->setParameter('spaceViewer', $user)
            ->setParameter('spaceAccessBackoffice', NoteSpaceAccessEnum::Backoffice)
            ->setParameter('spaceAccessMembers', NoteSpaceAccessEnum::Members);

        if (str_contains($qb->getDQL(), ':spaceWriterRoles')) {
            $qb->setParameter('spaceWriterRoles', [NoteSpaceRoleEnum::Editor, NoteSpaceRoleEnum::Manager]);
        }

        return $qb;
    }

    public function findPersonalFor(CoreUserInterface $user): ?NoteSpaceInterface
    {
        return $this->findOneBy(['personalUser' => $user]);
    }

    /**
     * Les espaces qu'une personne peut lire, le sien d'abord, puis par
     * position.
     *
     * @return list<NoteSpaceInterface>
     */
    public function findReadableFor(CoreUserInterface $user): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where(sprintf('s.id IN (%s)', self::readableSubquery()))
            ->orderBy('s.personalUser', Order::Descending->value)
            ->addOrderBy('s.position', Order::Ascending->value)
            ->addOrderBy('s.id', Order::Ascending->value);

        return self::bindViewer($qb, $user)->getQuery()->getResult();
    }

    public function findPublishedBySlug(string $slug): ?NoteSpaceInterface
    {
        return $this->createQueryBuilder('s')
            ->where('s.slug = :slug')
            ->andWhere('s.publishedAt IS NOT NULL')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.slug = :slug')
            ->setParameter('slug', $slug);

        if (null !== $exceptId) {
            $qb->andWhere('s.id != :except')->setParameter('except', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** La membre d'une personne sur un espace, si elle y est inscrite. */
    public function findMembership(NoteSpaceInterface $space, CoreUserInterface $user): ?NoteSpaceMember
    {
        return $this->getEntityManager()->getRepository(NoteSpaceMember::class)->findOneBy(['space' => $space, 'user' => $user]);
    }
}
