<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Pipeline\Enum\PipelineStageRoleEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\Types\Types;
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
        // The stage is read on every row, so it is fetched with them.
        return $this->createQueryBuilder('c')
            ->leftJoin('c.pipelineStage', 'stage')
            ->addSelect('stage')
            ->orderBy('c.legalName', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * What the pipeline board shows.
     *
     * Every prospect still in progress, wherever it stands, plus whatever
     * reached an outcome since `$outcomesSince`: a board that kept every deal
     * ever won or lost would bury the ones in progress under the history.
     * A client is on the board only in the won stage, the one place a client
     * belongs in a pipeline.
     *
     * @return list<CustomerInterface>
     */
    public function findForPipeline(DateTimeImmutable $outcomesSince): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.pipelineStage', 'stage')
            ->addSelect('stage')
            ->where('(stage.role IS NULL AND c.status = :prospect)')
            ->orWhere('(stage.role = :lost AND c.status = :prospect AND c.pipelineStageChangedAt >= :since)')
            ->orWhere('(stage.role = :won AND c.pipelineStageChangedAt >= :since)')
            ->setParameter('prospect', CustomerStatusEnum::Prospect)
            ->setParameter('lost', PipelineStageRoleEnum::Lost)
            ->setParameter('won', PipelineStageRoleEnum::Won)
            ->setParameter('since', $outcomesSince)
            ->orderBy('c.pipelinePosition', Order::Ascending->value)
            ->addOrderBy('c.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many follow-ups are due by `$today`: the figure the dashboard and
     * the side menu show. A lost prospect has none, its date is cleared when
     * it is lost.
     */
    public function countFollowUpsDue(DateTimeImmutable $today): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.nextFollowUpOn <= :today')
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The follow-ups due by `$today` that nobody has been reminded of yet.
     *
     * @return list<CustomerInterface>
     */
    public function findFollowUpsToNotify(DateTimeImmutable $today, int $limit = 200): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.nextFollowUpOn <= :today')
            ->andWhere('c.followUpNotifiedOn IS NULL')
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->orderBy('c.nextFollowUpOn', Order::Ascending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneBySourceReference(string $sourceReference): ?CustomerInterface
    {
        return $this->findOneBy(['sourceReference' => $sourceReference]);
    }

    /**
     * The customers created from these references, in one query.
     *
     * @param list<string> $sourceReferences
     *
     * @return list<CustomerInterface>
     */
    public function findBySourceReferences(array $sourceReferences): array
    {
        if ([] === $sourceReferences) {
            return [];
        }

        return $this->findBy(['sourceReference' => $sourceReferences]);
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
     * The customers whose company name, SIRET or SIREN contains the term.
     *
     * The number is compared without its spaces: it is often written in groups
     * of three, and it is stored in one piece.
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
