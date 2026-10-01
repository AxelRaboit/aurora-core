<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Access\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLink;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

use function array_filter;
use function array_map;
use function is_string;
use function max;
use function sprintf;

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
     * The latest thing any of its addresses saw, per contract id: sent,
     * opened or revoked, active or not, and, for a contract that expired, the
     * day its address lapsed. Only then: a signed contract keeps its link, and
     * that link running out a month later is not something that happened to
     * it.
     *
     * For the list's « last activity ». Reading the active link alone missed
     * everything that happened on a link since closed, which is every
     * contract the customer has answered.
     *
     * @param list<ContractInterface> $contracts
     *
     * @return array<int, DateTimeImmutable>
     */
    public function latestActivityForContracts(array $contracts): array
    {
        if ([] === $contracts) {
            return [];
        }

        /** @var list<array{id: int|string, sent: ?string, used: ?string, revoked: ?string, expires: ?string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.contract) AS id', 'MAX(l.sentAt) AS sent', 'MAX(l.lastUsedAt) AS used', 'MAX(l.revokedAt) AS revoked', 'MAX(CASE WHEN c.status = :expired THEN l.expiresAt ELSE :none END) AS expires')
            ->innerJoin('l.contract', 'c')
            ->andWhere('l.contract IN (:contracts)')
            ->setParameter('contracts', $contracts)
            ->setParameter('expired', ContractStatusEnum::Expired->value)
            ->setParameter('none', null)
            ->groupBy('l.contract')
            ->getQuery()
            ->getArrayResult();

        $now = new DateTimeImmutable();
        $latest = [];
        foreach ($rows as $row) {
            // A lapse is something that happened only once it has.
            $lapsed = null !== $row['expires'] && new DateTimeImmutable($row['expires']) <= $now ? $row['expires'] : null;
            $dates = array_map(
                static fn (string $value): DateTimeImmutable => new DateTimeImmutable($value),
                array_filter([$row['sent'], $row['used'], $row['revoked'], $lapsed], is_string(...)),
            );

            if ([] !== $dates) {
                $latest[(int) $row['id']] = max($dates);
            }
        }

        return $latest;
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
            // Only an address that went out: one saved for a mail that then
            // failed opens nothing anybody received.
            ->andWhere('l.sentAt IS NOT NULL')
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

    /**
     * Contracts out with the customer that no link opens any more: every
     * address handed out has run out or been revoked.
     *
     * @return list<ContractInterface>
     */
    public function findContractsWaitingWithoutActiveLink(): array
    {
        $active = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(ContractAccessLinkInterface::class, 'l')
            ->andWhere('l.contract = c')
            ->andWhere('l.revokedAt IS NULL')
            ->andWhere('l.sentAt IS NOT NULL')
            ->andWhere('l.expiresAt > :now');

        /* @var list<ContractInterface> */
        return $this->getEntityManager()->createQueryBuilder()
            ->select('c')
            ->from(ContractInterface::class, 'c')
            ->andWhere('c.status IN (:waiting)')
            ->andWhere(sprintf('NOT EXISTS (%s)', $active->getDQL()))
            ->setParameter('waiting', [ContractStatusEnum::Sent->value, ContractStatusEnum::Opened->value])
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /** The link that still opens this contract, if there is one. */
    public function findActiveFor(ContractInterface $contract): ?ContractAccessLinkInterface
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.contract = :contract')
            ->andWhere('l.revokedAt IS NULL')
            ->andWhere('l.sentAt IS NOT NULL')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('contract', $contract)
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('l.createdAt', Order::Descending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
