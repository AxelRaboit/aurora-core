<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteraction;
use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteractionInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<CustomerInteractionInterface>
 */
class CustomerInteractionRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerInteraction::class, CustomerInteractionInterface::class);
    }

    /**
     * A customer's exchanges, the latest first: the timeline is read to know
     * where things were left.
     *
     * @return list<CustomerInteractionInterface>
     */
    public function findForCustomer(CustomerInterface $customer): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('i.occurredAt', Order::Descending->value)
            ->addOrderBy('i.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The date of each customer's latest exchange, in one query, for the
     * board's cards.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, string> customer id => latest `occurred_at`, as stored
     */
    public function latestByCustomer(array $customerIds): array
    {
        if ([] === $customerIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.customer) AS customerId', 'MAX(i.occurredAt) AS latest')
            ->where('i.customer IN (:ids)')
            ->setParameter('ids', $customerIds)
            ->groupBy('i.customer')
            ->getQuery()
            ->getArrayResult();

        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row['customerId']] = (string) $row['latest'];
        }

        return $latest;
    }
}
