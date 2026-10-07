<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannel;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Enum\SpaceChatChannelKindEnum;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceChatChannelInterface>
 */
class SpaceChatChannelRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceChatChannel::class, SpaceChatChannelInterface::class);
    }

    /**
     * Every room of a space, main first, in the order the studio arranged them.
     *
     * @return list<SpaceChatChannelInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.space = :space')
            ->setParameter('space', $space)
            ->orderBy('c.position', Order::Ascending->value)
            ->addOrderBy('c.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The room a space was born with.
     *
     * Null only on a space whose rooms have not been seeded yet, which the
     * manager fixes the first time anybody opens the conversation rather than
     * leaving to a migration nobody reruns.
     */
    public function findMain(CustomerSpaceInterface $space): ?SpaceChatChannelInterface
    {
        return $this->createQueryBuilder('c')
            ->where('c.space = :space')
            ->andWhere('c.kind = :kind')
            ->setParameter('space', $space)
            ->setParameter('kind', SpaceChatChannelKindEnum::Main)
            ->orderBy('c.id', Order::Ascending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The rooms this account reads: the ones it was invited into, plus the
     * main one, which nobody is invited into because everybody is in it.
     *
     * @return list<SpaceChatChannelInterface>
     */
    /**
     * The members of these rooms, filled in place.
     *
     * A room's list shows who is in it; loaded room by room, that was a query
     * for each.
     *
     * @param list<SpaceChatChannelInterface> $channels
     */
    public function warmMembers(array $channels): void
    {
        if ([] === $channels) {
            return;
        }

        $this->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->addSelect('m')
            ->where('c IN (:channels)')
            ->setParameter('channels', $channels)
            ->getQuery()
            ->getResult();
    }

    public function findForUser(CustomerSpaceInterface $space, CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.members', 'm')
            ->where('c.space = :space')
            // A conversation put away leaves the list of whoever put it away,
            // and no other: `m.hiddenAt` is carried by the person.
            ->andWhere('c.kind = :main OR (m.user = :user AND m.hiddenAt IS NULL)')
            ->setParameter('space', $space)
            ->setParameter('main', SpaceChatChannelKindEnum::Main)
            ->setParameter('user', $user)
            ->groupBy('c.id')
            ->orderBy('c.position', Order::Ascending->value)
            ->addOrderBy('c.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The rooms this address reads: the ones opened to the client, plus the
     * private conversations it is in.
     *
     * The address and not "the client": a space can have several links out, and
     * a private conversation belongs to the person who was written to, not to
     * whoever holds any link to the space.
     *
     * @return list<SpaceChatChannelInterface>
     */
    public function findForLink(CustomerSpaceInterface $space, SpaceAccessLinkInterface $link): array
    {
        // **The channels open to the client, and nothing else.**
        //
        // The second branch also returned the private conversations this link
        // was a member of. It left with the route that opened them: a person
        // without an account has no private conversation, and the right that
        // allowed it - "peut commenter" - said something else entirely.
        //
        // The guard lives here rather than in the controller because it also
        // applies to conversations opened before this change: they stay in
        // the database, readable by the employee, and never come back up to
        // a link again.
        //
        // `$link` stays in the signature: the caller says which link it is
        // talking about, and the next rule depending on it will not have to
        // go back through every call.
        return $this->createQueryBuilder('c')
            ->where('c.space = :space')
            ->andWhere('c.openToClient = true')
            ->andWhere('c.kind != :direct')
            ->setParameter('space', $space)
            ->setParameter('direct', SpaceChatChannelKindEnum::Direct)
            ->orderBy('c.position', Order::Ascending->value)
            ->addOrderBy('c.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The private conversation between these two, if it was ever opened.
     *
     * Asked by participant rather than by name, because the name is only what
     * the panel prints: two people have one conversation whatever either of
     * them is called now.
     *
     * @param array{user?: CoreUserInterface|null, link?: SpaceAccessLinkInterface|null} $first
     * @param array{user?: CoreUserInterface|null, link?: SpaceAccessLinkInterface|null} $second
     */
    public function findDirectBetween(CustomerSpaceInterface $space, array $first, array $second): ?SpaceChatChannelInterface
    {
        $builder = $this->createQueryBuilder('c')
            ->innerJoin('c.members', 'a')
            ->innerJoin('c.members', 'b')
            ->where('c.space = :space')
            ->andWhere('c.kind = :direct')
            ->setParameter('space', $space)
            ->setParameter('direct', SpaceChatChannelKindEnum::Direct)
            ->setMaxResults(1);

        foreach ([['a', $first], ['b', $second]] as [$alias, $side]) {
            if (($side['user'] ?? null) instanceof CoreUserInterface) {
                $builder->andWhere(sprintf('%s.user = :%s', $alias, $alias))
                    ->setParameter($alias, $side['user']);

                continue;
            }

            if (($side['link'] ?? null) instanceof SpaceAccessLinkInterface) {
                $builder->andWhere(sprintf('%s.link = :%s', $alias, $alias))
                    ->setParameter($alias, $side['link']);

                continue;
            }

            return null;
        }

        return $builder->getQuery()->getOneOrNullResult();
    }

    /** The place a new room takes: after the last one. */
    public function nextPosition(CustomerSpaceInterface $space): int
    {
        $highest = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->where('c.space = :space')
            ->setParameter('space', $space)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : (int) $highest + 1;
    }
}
