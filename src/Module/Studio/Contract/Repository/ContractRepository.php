<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLink;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<ContractInterface>
 */
class ContractRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class, ContractInterface::class);
    }

    /**
     * Newest first, with the customer joined.
     *
     * Unlike the customer list, this one is read to see what is happening now:
     * what went out this week, what is waiting for a signature.
     *
     * @return list<ContractInterface>
     */
    public function findAllForIndex(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('cu')
            ->innerJoin('c.customer', 'cu')
            ->orderBy('c.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every frozen contract, for the verification command.
     *
     * Ordered by id so a run reports in a stable order and two runs can be
     * compared line by line.
     *
     * @return list<ContractInterface>
     */
    public function findFrozen(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.frozenAt IS NOT NULL')
            ->orderBy('c.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many contracts name this customer, whatever their state.
     *
     * Asked before a customer is deleted. The foreign key is `RESTRICT`, so
     * the database already refuses - but it refuses with an SQL error in the
     * middle of a request, which reaches the screen as a 500 and tells the
     * reader nothing about what they did.
     */
    public function countForCustomer(CustomerInterface $customer): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many frozen contracts still point at a version of this template.
     *
     * Frozen only: a draft still being written can be rebuilt from another
     * trame, while a contract that went out to somebody is a record, and the
     * trail from it to the wording it was made from is part of what makes it
     * one.
     */
    public function countFrozenUsingTemplate(ContractTemplateInterface $template): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->leftJoin('c.bodyVersion', 'bv')
            ->leftJoin('c.annexVersion', 'av')
            ->andWhere('c.frozenAt IS NOT NULL')
            ->andWhere('bv.template = :template OR av.template = :template')
            ->setParameter('template', $template)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many sealed amendments a contract already carries.
     *
     * Sealed only, because the rank is minted at the freeze: a draft
     * abandoned before it went anywhere must not have consumed a number that
     * an accountant would then look for and never find.
     */
    public function countSealedAmendmentsOf(ContractInterface $parent): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.amends = :parent')
            ->andWhere('c.frozenAt IS NOT NULL')
            ->setParameter('parent', $parent)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The amendments of a contract, oldest first.
     *
     * Oldest first because they are read as a history: the last one is what is
     * in force, and getting there by reading forward is how somebody checks
     * that nothing is missing in between.
     *
     * @return list<ContractInterface>
     */
    public function findAmendmentsOf(ContractInterface $parent): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.amends = :parent')
            ->setParameter('parent', $parent)
            ->orderBy('c.createdAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Contracts a reminder is due on.
     *
     * Sent or opened and nothing more: signed, concluded, refused, expired and
     * revoked are all answers, and chasing an answer is what makes automatic
     * mail obnoxious. The clock runs from the last reminder, or from the send
     * when there has not been one, so the first chase happens `$days` after
     * the contract went out rather than `$days` after it was sealed.
     *
     * @return list<ContractInterface>
     */
    public function findDueForReminder(DateTimeImmutable $before, int $maxReminders): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('cu')
            ->innerJoin('c.customer', 'cu')
            ->innerJoin(ContractAccessLink::class, 'l', Join::WITH, 'l.contract = c')
            ->andWhere('c.frozenAt IS NOT NULL')
            ->andWhere('c.status IN (:waiting)')
            ->andWhere('c.reminderCount < :max')
            ->andWhere('COALESCE(c.lastReminderAt, l.sentAt) <= :before')
            ->andWhere('l.revokedAt IS NULL')
            ->andWhere('l.sentAt IS NOT NULL')
            ->setParameter('waiting', [ContractStatusEnum::Sent->value, ContractStatusEnum::Opened->value])
            ->setParameter('max', $maxReminders)
            ->setParameter('before', $before)
            ->orderBy('c.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }
}
