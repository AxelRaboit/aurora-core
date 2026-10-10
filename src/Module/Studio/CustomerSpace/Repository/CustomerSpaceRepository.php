<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<CustomerSpaceInterface>
 */
class CustomerSpaceRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerSpace::class, CustomerSpaceInterface::class);
    }

    /**
     * Every space, active ones first, then by name.
     *
     * Archived rows are returned rather than filtered out: the page keeps them
     * behind its own switch, and a repository that hid them would need a second
     * method the day somebody looks for last year's client. Ordering by status
     * puts them at the bottom, which is the same answer at a tenth of the cost.
     *
     * The customer and the members are joined now, not looked up per row: the
     * list shows the company's name and the team's faces, and both are one
     * query here and one per space without it.
     *
     * A space in the trash is not in it: it is waiting to be restored or
     * destroyed, and nothing but the trash screen lists it.
     *
     * @return list<CustomerSpaceInterface>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('c', 'm', 'u')
            ->join('s.customer', 'c')
            ->leftJoin('s.members', 'm')
            ->leftJoin('m.user', 'u')
            ->where('s.deletedAt IS NULL')
            ->orderBy('s.status', Order::Ascending->value)
            ->addOrderBy('s.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The spaces a person sees.
     *
     * **Being a member finally decides something.** The screen has a team put
     * together, which reads as an assignment; until now it was not one, and
     * anyone who could see a space saw them all. A teammate now only sees
     * their own.
     *
     * `$seesAll` rather than a role read here: the repository has no business
     * knowing about security, and the caller already knows whether the person
     * bypasses privileges. That is also what keeps the method testable
     * without a token.
     *
     * @return list<CustomerSpaceInterface>
     */
    public function findVisibleTo(CoreUserInterface $user, bool $seesAll): array
    {
        if ($seesAll) {
            return $this->findAllOrdered();
        }

        // The ids first, the list next: filtering on the join that brings the
        // members back would only return the members kept by the filter, so a
        // team cut off from itself on every card.
        $ids = $this->createQueryBuilder('s')
            ->select('s.id')
            ->join('s.members', 'm')
            ->where('m.user = :user')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->addSelect('c', 'm', 'u')
            ->join('s.customer', 'c')
            ->leftJoin('s.members', 'm')
            ->leftJoin('m.user', 'u')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('s.status', Order::Ascending->value)
            ->addOrderBy('s.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Does this person see this space?
     *
     * Asked separately from the list because a space's screen asks the same
     * thing for a single row, and answering it by loading the others would be
     * paying for the list to answer a yes/no question.
     */
    public function isVisibleTo(CustomerSpaceInterface $space, CoreUserInterface $user, bool $seesAll): bool
    {
        if ($seesAll) {
            return true;
        }

        return 0 < (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.members', 'm')
            ->where('s = :space')
            ->andWhere('m.user = :user')
            ->setParameter('space', $space)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many spaces per status.
     *
     * Grouped rather than one count per status: the dashboard shows them all,
     * and three queries for three numbers coming out of the same table would
     * be three round trips for nothing.
     *
     * @return array<string, int>
     */
    public function countGroupedByStatus(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS total')
            ->where('s.deletedAt IS NULL')
            ->groupBy('s.status')
            ->getQuery()
            ->getScalarResult();

        $counts = [];

        foreach ($rows as $row) {
            $status = $row['status'];
            $counts[$status instanceof CustomerSpaceStatusEnum ? $status->value : (string) $status] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * A space's team and each member's account, filled in place, unless they
     * are loaded already.
     *
     * A space opened on its own read its members, then each member's user:
     * a query per person, on every screen of the space and on every write of
     * its client, which tells the team. The list of spaces joins them already
     * and pays nothing here.
     */
    public function warmTeam(CustomerSpaceInterface $space): void
    {
        $members = $space->getMembers();
        if (!$members instanceof PersistentCollection || $members->isInitialized()) {
            return;
        }

        $this->createQueryBuilder('s')
            ->leftJoin('s.members', 'm')
            ->leftJoin('m.user', 'u')
            ->addSelect('m', 'u')
            ->where('s = :space')
            ->setParameter('space', $space)
            ->getQuery()
            ->getResult();
    }

    /**
     * The people working for this customer: the members of its spaces.
     *
     * Archived spaces count, trashed ones do not. An archived space is a
     * finished campaign whose team still knows the customer; a trashed one is
     * waiting to disappear. One person on two of the customer's spaces is one
     * person, so the list is keyed while collecting.
     *
     * The spaces are read with their teams in one query, then walked: a
     * customer has a handful of them, and selecting the users directly would
     * need a root on the member entity that this repository does not own.
     *
     * @return list<CoreUserInterface>
     */
    public function findTeamOfCustomer(CustomerInterface $customer): array
    {
        /** @var list<CustomerSpaceInterface> $spaces */
        $spaces = $this->createQueryBuilder('s')
            ->addSelect('m', 'u')
            ->leftJoin('s.members', 'm')
            ->leftJoin('m.user', 'u')
            ->where('s.customer = :customer')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getResult();

        $users = [];

        foreach ($spaces as $space) {
            foreach ($space->getMembers() as $member) {
                $user = $member->getUser();
                $users[$user->getUserIdentifier()] = $user;
            }
        }

        return array_values($users);
    }

    /**
     * How many spaces name this customer, those in the trash included.
     *
     * Asked before a customer is deleted, so the refusal can say how many
     * spaces stand in the way instead of letting the foreign key answer with a
     * driver exception. A space in the trash still names its customer, and
     * still holds the work a restore would bring back: it stands in the way
     * until it is destroyed for good.
     */
    public function countForCustomer(CustomerInterface $customer): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The palette slot the fewest spaces are wearing.
     *
     * Not "the first free one": spaces are deleted and re-created, and a
     * first-free walk hands the same colour to the two most recent ones as soon
     * as an early space goes. Counting spreads them instead, and past eight it
     * keeps spreading rather than piling onto slot one.
     */
    public function leastUsedColourSlot(): int
    {
        /** @var list<array{slot: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.colourSlot AS slot', 'COUNT(s.id) AS total')
            ->groupBy('s.colourSlot')
            ->getQuery()
            ->getArrayResult();

        $used = [];
        foreach ($rows as $row) {
            $used[(int) $row['slot']] = (int) $row['total'];
        }

        $best = CustomerSpace::DEFAULT_COLOUR_SLOT;
        $bestCount = PHP_INT_MAX;

        for ($slot = 1; $slot <= CustomerSpace::MAX_COLOUR_SLOT; ++$slot) {
            $count = $used[$slot] ?? 0;

            if ($count < $bestCount) {
                $best = $slot;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * The ids of the spaces this person is a member of.
     *
     * The "membership" half of {@see findVisibleTo()}, without loading the
     * spaces: the global search only needs to know where to look, and loading
     * each space with its team to keep only the id would be paying for the
     * whole list on every keystroke.
     *
     * @return list<int>
     */
    public function findIdsWhereMember(CoreUserInterface $user): array
    {
        return array_map(intval(...), $this->createQueryBuilder('s')
            ->select('s.id')
            ->join('s.members', 'm')
            ->where('m.user = :user')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * The spaces whose name, or the customer's, contains the term.
     *
     * `$spaceIds` null means "all" (someone who sees everything); a list
     * narrows the search to those spaces, and an empty list returns nothing.
     * Ordered like the spaces list: active ones first.
     *
     * @param list<int>|null $spaceIds
     *
     * @return list<CustomerSpaceInterface>
     */
    public function searchByName(string $term, ?array $spaceIds, int $limit): array
    {
        if ('' === mb_trim($term) || [] === $spaceIds) {
            return [];
        }

        $builder = $this->createQueryBuilder('s')
            ->addSelect('c')
            ->join('s.customer', 'c')
            ->where('LOWER(s.name) LIKE :term OR LOWER(c.legalName) LIKE :term')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('s.status', Order::Ascending->value)
            ->addOrderBy('s.name', Order::Ascending->value)
            ->setMaxResults($limit);

        if (null !== $spaceIds) {
            $builder->andWhere('s.id IN (:ids)')->setParameter('ids', $spaceIds);
        }

        return $builder->getQuery()->getResult();
    }

    /** A space in the trash: what gets restored or destroyed for good. */
    public function findTrashed(int $id): ?CustomerSpaceInterface
    {
        return $this->createQueryBuilder('s')
            ->where('s.id = :id')
            ->andWhere('s.deletedAt IS NOT NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Everything the spaces trash holds, latest first, with the customer and
     * the team: the screen names the customer, and the caller keeps the ones
     * the person has the right to see.
     *
     * @return list<CustomerSpaceInterface>
     */
    public function findAllTrashed(): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('c', 'm', 'u')
            ->join('s.customer', 'c')
            ->leftJoin('s.members', 'm')
            ->leftJoin('m.user', 'u')
            ->where('s.deletedAt IS NOT NULL')
            ->orderBy('s.deletedAt', Order::Descending->value)
            ->addOrderBy('s.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The ones in the trash since before this date: the scheduled purge
     * destroys them.
     *
     * @return list<CustomerSpaceInterface>
     */
    public function findTrashedBefore(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.deletedAt IS NOT NULL')
            ->andWhere('s.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
