<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMember;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMemberInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
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
     * The spaces a person can read, in one DQL subquery.
     *
     * **A single definition of "who sees what"**, which every query of the
     * module reuses: their personal space, the ones they own, the ones they
     * are a member of when access is for members, and the ones open to the
     * whole back office. Every list that rewrote its own rule would have
     * ended up forgetting a branch, and that is how a note opens by mistake.
     *
     * The `:spaceViewer` parameter and the three access parameters are set
     * by {@see self::bindViewer()}.
     */
    public static function readableSubquery(string $prefix = 'rs'): string
    {
        return sprintf(
            'SELECT %1$s.id FROM %2$s %1$s LEFT JOIN %1$s.members %1$sm WITH %1$sm.user = :spaceViewer '
            .'WHERE %1$s.deletedAt IS NULL AND ('
            .'%1$s.personalUser = :spaceViewer OR %1$s.owner = :spaceViewer '
            .'OR (%1$s.owner IS NULL AND %1$s.personalUser IS NULL AND :spaceViewerAdopts = TRUE) '
            .'OR %1$s.access = :spaceAccessBackoffice '
            .'OR (%1$s.access = :spaceAccessMembers AND %1$sm.id IS NOT NULL))',
            $prefix,
            NoteSpace::class,
        );
    }

    /**
     * The spaces where a person can write: the same, with the role.
     *
     * Editor or manager through their membership, or through the default
     * role of a space open to the whole back office.
     */
    public static function writableSubquery(string $prefix = 'ws'): string
    {
        return sprintf(
            'SELECT %1$s.id FROM %2$s %1$s LEFT JOIN %1$s.members %1$sm WITH %1$sm.user = :spaceViewer '
            .'WHERE %1$s.deletedAt IS NULL AND ('
            .'%1$s.personalUser = :spaceViewer OR %1$s.owner = :spaceViewer '
            .'OR (%1$s.owner IS NULL AND %1$s.personalUser IS NULL AND :spaceViewerAdopts = TRUE) '
            .'OR (%1$s.access = :spaceAccessBackoffice AND (%1$s.defaultRole IN (:spaceWriterRoles) OR %1$sm.role IN (:spaceWriterRoles))) '
            .'OR (%1$s.access = :spaceAccessMembers AND %1$sm.role IN (:spaceWriterRoles)))',
            $prefix,
            NoteSpace::class,
        );
    }

    /**
     * Sets the parameters of the two subqueries on a query.
     *
     * Only the ones the query mentions: Doctrine refuses an extra parameter,
     * and a read query does not mention the write roles.
     */
    public static function bindViewer(QueryBuilder $qb, CoreUserInterface $user): QueryBuilder
    {
        $qb
            ->setParameter('spaceViewer', $user)
            ->setParameter('spaceViewerAdopts', self::isAdmin($user))
            ->setParameter('spaceAccessBackoffice', NoteSpaceAccessEnum::Backoffice)
            ->setParameter('spaceAccessMembers', NoteSpaceAccessEnum::Members);

        if (str_contains($qb->getDQL(), ':spaceWriterRoles')) {
            $qb->setParameter('spaceWriterRoles', [NoteSpaceRoleEnum::Editor, NoteSpaceRoleEnum::Manager]);
        }

        return $qb;
    }

    /**
     * Administrator, in the sense of orphaned spaces: those fall to the
     * administrators when their owner has left.
     */
    public static function isAdmin(CoreUserInterface $user): bool
    {
        return UserRoleEnum::administers($user->getRoles());
    }

    public function findPersonalFor(CoreUserInterface $user): ?NoteSpaceInterface
    {
        return $this->findOneBy(['personalUser' => $user]);
    }

    /**
     * The spaces a person can read, their own first, then by position.
     *
     * @return list<NoteSpaceInterface>
     */
    public function findReadableFor(CoreUserInterface $user): array
    {
        // Their own first, through an expression and not a descending sort
        // on the column: PostgreSQL puts null values first in a descending
        // sort, and the shared spaces then came first.
        $qb = $this->createQueryBuilder('s')
            ->addSelect('CASE WHEN s.personalUser IS NULL THEN 1 ELSE 0 END AS HIDDEN sharedLast')
            ->where(sprintf('s.id IN (%s)', self::readableSubquery()))
            ->orderBy('sharedLast', Order::Ascending->value)
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

    /** A person's membership of a space, if they are a member. */
    public function findMembership(NoteSpaceInterface $space, CoreUserInterface $user): ?NoteSpaceMemberInterface
    {
        return $this->getEntityManager()->getRepository(NoteSpaceMember::class)->findOneBy(['space' => $space, 'user' => $user]);
    }

    /**
     * A person's memberships of these spaces, in one query.
     *
     * @param list<NoteSpaceInterface> $spaces
     *
     * @return list<NoteSpaceMemberInterface>
     */
    public function findMembershipsOf(CoreUserInterface $user, array $spaces): array
    {
        if ([] === $spaces) {
            return [];
        }

        return $this->getEntityManager()->getRepository(NoteSpaceMember::class)->findBy(['space' => $spaces, 'user' => $user]);
    }

    /**
     * A space's members with their account, in one query.
     *
     * @return list<NoteSpaceMemberInterface>
     */
    public function findMembersOf(NoteSpaceInterface $space): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('m', 'u')
            ->from(NoteSpaceMember::class, 'm')
            ->join('m.user', 'u')
            ->where('m.space = :space')
            ->setParameter('space', $space)
            ->orderBy('m.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }
}
