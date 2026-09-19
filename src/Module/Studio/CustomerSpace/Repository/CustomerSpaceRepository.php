<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Doctrine\Common\Collections\Order;
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
     * @return list<CustomerSpaceInterface>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('c', 'm', 'u')
            ->join('s.customer', 'c')
            ->leftJoin('s.members', 'm')
            ->leftJoin('m.user', 'u')
            ->orderBy('s.status', Order::Ascending->value)
            ->addOrderBy('s.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many spaces name this customer.
     *
     * Asked before a customer is deleted, so the refusal can say how many
     * spaces stand in the way instead of letting the foreign key answer with a
     * driver exception.
     */
    /**
     * Combien d'espaces par état.
     *
     * Groupé plutôt qu'un compte par état : le tableau de bord les affiche
     * tous, et trois requêtes pour trois nombres qui sortent de la même table
     * seraient trois allers-retours pour rien.
     *
     * @return array<string, int>
     */
    public function countGroupedByStatus(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS total')
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
}
