<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmission;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<FormSubmissionInterface>
 */
class FormSubmissionRepository extends ResolveTargetEntityRepository
{
    use PaginationTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormSubmission::class, FormSubmissionInterface::class);
    }

    /**
     * @return array{items: list<FormSubmissionInterface>, total: int, page: int, totalPages: int}
     */
    public function findPaginatedByForm(FormInterface $form, int $page, int $limit): array
    {
        $items = $this->createQueryBuilder('s')
            ->where('s.form = :form')
            ->setParameter('form', $form)
            ->orderBy('s.submittedAt', Order::Descending->value);

        $count = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.form = :form')
            ->setParameter('form', $form);

        return $this->paginate($items, $count, $page, $limit);
    }

    /**
     * Every submission of one form, oldest first - what an export writes.
     *
     * @return list<FormSubmissionInterface>
     */
    public function findAllByForm(FormInterface $form): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.form = :form')
            ->setParameter('form', $form)
            ->orderBy('s.submittedAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Submissions older than a date, oldest first - what the retention purge
     * removes.
     *
     * Batched by the caller rather than returned whole: a site left for a year
     * with the setting off, then turned on, has every submission it ever took
     * to delete in one pass.
     *
     * @return list<FormSubmissionInterface>
     */
    public function findSubmittedBefore(DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.submittedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('s.submittedAt', Order::Ascending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return array<int, int> form id → submission count */
    public function countByForm(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.form) AS formId', 'COUNT(s.id) AS total')
            ->groupBy('s.form')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['formId']] = (int) $row['total'];
        }

        return $counts;
    }
}
