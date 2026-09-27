<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Access\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLink;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<ContractAccessLinkInterface>
 */
class ContractAccessLinkRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractAccessLink::class, ContractAccessLinkInterface::class);
    }

    /**
     * The row a selector names, with its contract and customer joined.
     *
     * By selector only. The secret is never a query parameter: it is compared
     * in constant time against the stored hash once the row is in hand, which
     * is what keeps a timing difference from telling somebody they guessed
     * half of it.
     */
    public function findBySelector(string $selector): ?ContractAccessLinkInterface
    {
        return $this->createQueryBuilder('l')
            ->addSelect('c', 'cu')
            ->innerJoin('l.contract', 'c')
            ->innerJoin('c.customer', 'cu')
            ->andWhere('l.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every link ever minted for a contract, newest first.
     *
     * Revoked and expired ones included: the back office shows the history of
     * what was sent to whom, and a link that was revoked is part of that.
     *
     * @return list<ContractAccessLinkInterface>
     */
    public function findForContract(ContractInterface $contract): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.contract = :contract')
            ->setParameter('contract', $contract)
            ->orderBy('l.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The link that still opens each of these contracts, keyed by contract id.
     *
     * One query for a list, where the list asked `findActiveFor()` row by row.
     * The newest wins, as it does there.
     *
     * @param list<ContractInterface> $contracts
     *
     * @return array<int, ContractAccessLinkInterface>
     */
    public function findActiveForContracts(array $contracts): array
    {
        if ([] === $contracts) {
            return [];
        }

        /** @var list<ContractAccessLinkInterface> $links */
        $links = $this->createQueryBuilder('l')
            ->andWhere('l.contract IN (:contracts)')
            ->andWhere('l.revokedAt IS NULL')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('contracts', $contracts)
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('l.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();

        $byContract = [];
        foreach ($links as $link) {
            $byContract[(int) $link->getContract()->getId()] ??= $link;
        }

        return $byContract;
    }

    /** The link that still opens this contract, if there is one. */
    public function findActiveFor(ContractInterface $contract): ?ContractAccessLinkInterface
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.contract = :contract')
            ->andWhere('l.revokedAt IS NULL')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('contract', $contract)
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('l.createdAt', Order::Descending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
