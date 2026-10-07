<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
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
        // The pinned versions and their templates come along, each one row per
        // contract. Whether a version is outdated is read off its template's
        // versions: those are loaded after, for every template at once, since
        // joining them here would multiply each contract by two lists.
        /** @var list<ContractInterface> $contracts */
        $contracts = $this->createQueryBuilder('c')
            ->addSelect('cu', 'bv', 'bt', 'av', 'at')
            ->innerJoin('c.customer', 'cu')
            ->leftJoin('c.bodyVersion', 'bv')
            ->leftJoin('bv.template', 'bt')
            ->leftJoin('c.annexVersion', 'av')
            ->leftJoin('av.template', 'at')
            ->orderBy('c.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();

        $templates = [];
        foreach ($contracts as $contract) {
            foreach ([$contract->getBodyVersion(), $contract->getAnnexVersion()] as $version) {
                if (null !== $version) {
                    $templates[(int) $version->getTemplate()->getId()] = $version->getTemplate();
                }
            }
        }

        if ([] !== $templates) {
            $entityManager = $this->getEntityManager();
            $versionClass = $this->getClassMetadata()->getAssociationTargetClass('bodyVersion');

            $entityManager->createQueryBuilder()
                ->select('t', 'v')
                ->from($entityManager->getClassMetadata($versionClass)->getAssociationTargetClass('template'), 't')
                ->leftJoin('t.versions', 'v')
                ->where('t IN (:templates)')
                ->setParameter('templates', array_values($templates))
                ->getQuery()
                ->getResult();
        }

        return $contracts;
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
     * How many contracts each template is used by, drafts included, keyed by
     * template id: as body or as annex, counted once per contract.
     *
     * @return array<int, int>
     */
    public function countByTemplate(): array
    {
        $counts = [];

        foreach (['bodyVersion', 'annexVersion'] as $relation) {
            $rows = $this->createQueryBuilder('c')
                ->select('IDENTITY(v.template) AS template, COUNT(c.id) AS total')
                ->innerJoin('c.'.$relation, 'v')
                ->groupBy('v.template')
                ->getQuery()
                ->getScalarResult();

            foreach ($rows as $row) {
                $counts[(int) $row['template']] = ($counts[(int) $row['template']] ?? 0) + (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * How many contracts each customer has, keyed by customer id.
     *
     * @return array<int, int>
     */
    public function countByCustomer(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.customer) AS customer, COUNT(c.id) AS total')
            ->groupBy('c.customer')
            ->getQuery()
            ->getScalarResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['customer']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * How many contracts per state.
     *
     * The nine states in one query. The dashboard only highlights one, the
     * one awaiting a signature, but the whole breakdown is what puts it in
     * context: two contracts pending out of three is not the same news as
     * two out of forty.
     *
     * @return array<string, int>
     */
    public function countGroupedByStatus(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.status AS status, COUNT(c.id) AS total')
            ->groupBy('c.status')
            ->getQuery()
            ->getScalarResult();

        $counts = [];

        foreach ($rows as $row) {
            $status = $row['status'];
            $counts[$status instanceof ContractStatusEnum ? $status->value : (string) $status] = (int) $row['total'];
        }

        return $counts;
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
    /**
     * Every contract built on the template, drafts included.
     *
     * A template cannot be deleted while any of them exists: its versions are
     * `SET NULL` on the contracts, so deleting it used to leave its drafts with
     * no body and no warning.
     */
    public function countUsingTemplate(ContractTemplateInterface $template): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->leftJoin('c.bodyVersion', 'bv')
            ->leftJoin('c.annexVersion', 'av')
            ->andWhere('bv.template = :template OR av.template = :template')
            ->setParameter('template', $template)
            ->getQuery()
            ->getSingleScalarResult();
    }

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

    /**
     * The contracts whose reference, client or template contains the term.
     *
     * A contract has no title of its own: it is found by its reference, by the
     * company it was written for, or by the template it starts from
     * ("contrat mensuel"). The most recent first, like the list.
     *
     * @return list<ContractInterface>
     */
    public function search(string $term, int $limit): array
    {
        if ('' === mb_trim($term)) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->addSelect('cu', 'bv', 'bt')
            ->innerJoin('c.customer', 'cu')
            ->leftJoin('c.bodyVersion', 'bv')
            ->leftJoin('bv.template', 'bt')
            ->where('LOWER(c.reference) LIKE :term OR LOWER(cu.legalName) LIKE :term OR LOWER(bt.name) LIKE :term')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('c.createdAt', Order::Descending->value)
            ->addOrderBy('c.id', Order::Descending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
