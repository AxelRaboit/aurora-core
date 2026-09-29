<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<CustomerInterface>
 */
class CustomerRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class, CustomerInterface::class);
    }

    /**
     * Every customer, by legal name.
     *
     * Alphabetical rather than newest-first: this list is read to find a known
     * company, not to see what changed today.
     *
     * @return list<CustomerInterface>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            // The account is joined now rather than looked up per row: the list
            // shows whether a customer has a login, which is one query here and
            // one per customer without it.
            ->addSelect('u')
            ->leftJoin('c.user', 'u')
            ->orderBy('c.legalName', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The customer holding this SIRET, if any.
     *
     * Used to answer "this number is already recorded" with the row that holds
     * it, so the message can name the company instead of just refusing.
     */
    public function findOneBySiret(string $siret): ?CustomerInterface
    {
        return $this->findOneBy(['siret' => $siret]);
    }

    /**
     * Les clients dont la raison sociale, le SIRET ou le SIREN contient le
     * terme.
     *
     * Le numéro est comparé sans ses espaces : il s'écrit souvent par groupes
     * de trois, et il est enregistré d'un seul tenant.
     *
     * @return list<CustomerInterface>
     */
    public function searchByNameOrNumber(string $term, int $limit): array
    {
        if ('' === mb_trim($term)) {
            return [];
        }

        $builder = $this->createQueryBuilder('c')
            ->where('LOWER(c.legalName) LIKE :term')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('c.legalName', Order::Ascending->value)
            ->setMaxResults($limit);

        $digits = preg_replace('/\s+/', '', $term) ?? '';
        if (1 === preg_match('/^\d{3,14}$/', $digits)) {
            $builder
                ->orWhere('c.siret LIKE :number OR c.siren LIKE :number')
                ->setParameter('number', LikePattern::contains($digits));
        }

        return $builder->getQuery()->getResult();
    }
}
