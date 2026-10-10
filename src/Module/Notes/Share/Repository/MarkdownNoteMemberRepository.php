<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteMember;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteMemberInterface;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
use Aurora\Module\Notes\Space\Hosting\NoteSpaceScope;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function sprintf;
use function str_contains;

/**
 * @extends ResolveTargetEntityRepository<MarkdownNoteMemberInterface>
 */
class MarkdownNoteMemberRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarkdownNoteMember::class, MarkdownNoteMemberInterface::class);
    }

    /**
     * The notes handed to a person one by one, as a DQL subquery.
     *
     * **A single definition, like the spaces have one.** Every list in the
     * module filters on the spaces a person reads; a note shared on its own
     * lives in a space they cannot read, so each of those lists has to widen
     * by exactly this set and no other. Writing the join by hand in each
     * query is how one of them ends up forgetting it, and a note shared with
     * somebody then fails to appear in the one place they went looking.
     *
     * Joined rather than `IDENTITY()` in a subselect: the join is free here
     * and reads the same on every Doctrine version.
     *
     * Narrowed by the request's scope like the spaces are
     * ({@see NoteSpaceScope}): a hosted note handed to somebody on its own
     * stays out of the Notes module's lists, like the rest of its space.
     *
     * The `:noteViewer` parameter is set by {@see self::bindViewer()}, the
     * scope parameters by `NoteSpaceRepository::bindViewer()`, which every
     * query using this one also calls.
     */
    public static function grantedSubquery(string $prefix = 'gm'): string
    {
        return sprintf(
            'SELECT %1$sn.id FROM %2$s %1$s JOIN %1$s.note %1$sn JOIN %1$sn.space %1$ss WHERE %1$s.user = :noteViewer AND %3$s',
            $prefix,
            MarkdownNoteMember::class,
            NoteSpaceScope::clause($prefix.'s'),
        );
    }

    /**
     * The same, restricted to the notes they may write.
     *
     * The role parameter is set by {@see self::bindViewer()}, which only sets
     * it when the query mentions it - Doctrine refuses a parameter nothing
     * uses, and a read query does not name the writing roles.
     */
    public static function grantedWritableSubquery(string $prefix = 'gw'): string
    {
        return sprintf(
            'SELECT %1$sn.id FROM %2$s %1$s JOIN %1$s.note %1$sn JOIN %1$sn.space %1$ss WHERE %1$s.user = :noteViewer AND %1$s.role IN (:noteWriterRoles) AND %3$s',
            $prefix,
            MarkdownNoteMember::class,
            NoteSpaceScope::clause($prefix.'s'),
        );
    }

    /** Sets the parameters of the two subqueries, and only the ones used. */
    public static function bindViewer(QueryBuilder $queryBuilder, CoreUserInterface $user): QueryBuilder
    {
        $queryBuilder->setParameter('noteViewer', $user);

        if (str_contains($queryBuilder->getDQL(), ':noteWriterRoles')) {
            $queryBuilder->setParameter('noteWriterRoles', [NoteMemberRoleEnum::Editor]);
        }

        return $queryBuilder;
    }

    public function findOneFor(MarkdownNoteInterface $note, CoreUserInterface $user): ?MarkdownNoteMemberInterface
    {
        return $this->findOneBy(['note' => $note, 'user' => $user]);
    }

    /**
     * A person's role on a note, or null when the note was not handed to them.
     *
     * Deliberately says nothing about the space: the caller adds the two
     * together, and that caller is {@see NoteSpaceAccess},
     * the module's single place where access is decided.
     */
    public function roleFor(MarkdownNoteInterface $note, CoreUserInterface $user): ?NoteMemberRoleEnum
    {
        return $this->findOneFor($note, $user)?->getRole();
    }

    /**
     * A note's guest list, with the accounts, in one query.
     *
     * Oldest first, so adding somebody puts them at the bottom where the eye
     * last was.
     *
     * @return list<MarkdownNoteMemberInterface>
     */
    public function findForNote(MarkdownNoteInterface $note): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->where('m.note = :note')
            ->setParameter('note', $note)
            ->orderBy('m.createdAt', Order::Ascending->value)
            ->addOrderBy('m.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The notes handed to a person one by one, each with their role.
     *
     * **What the screen cannot work out for itself.** Every other right in
     * the module is read off the note's space, which the page already holds;
     * these notes live in spaces their reader knows nothing about, so without
     * this map the page would have no way to tell whether it may offer to
     * edit - and it defaulted to yes, which is a save that fails after the
     * typing.
     *
     * One query for the whole list rather than a lookup per row: the library
     * draws hundreds of cards.
     *
     * @return array<int, string> note id => role value
     */
    public function findRolesFor(CoreUserInterface $user): array
    {
        /** @var list<array{id: int, role: NoteMemberRoleEnum}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('n.id', 'm.role')
            ->join('m.note', 'n')
            ->where('m.user = :user')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $roles = [];
        foreach ($rows as $row) {
            $roles[(int) $row['id']] = $row['role']->value;
        }

        return $roles;
    }
}
