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

        $this->warmForm($form);

        return $this->paginate($items, $count, $page, $limit);
    }

    /**
     * One submission with its form, the form's fields and every label, for
     * the worker that mails and posts it.
     */
    public function findForDelivery(int $id): ?FormSubmissionInterface
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.form', 'f')
            ->leftJoin('f.translations', 't')
            ->leftJoin('f.fields', 'field')
            ->leftJoin('field.translations', 'ft')
            ->addSelect('f', 't', 'field', 'ft')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The form's translations, fields and field labels, filled in place.
     *
     * Every row of a list or an export is labelled from the same form, and
     * the labeler read each field's translations on its own.
     */
    private function warmForm(FormInterface $form): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->select('f', 't', 'field', 'ft')
            ->from($this->getClassMetadata()->getAssociationTargetClass('form'), 'f')
            ->leftJoin('f.translations', 't')
            ->leftJoin('f.fields', 'field')
            ->leftJoin('field.translations', 'ft')
            ->where('f = :form')
            ->setParameter('form', $form)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every submission of one form, oldest first - what an export writes.
     *
     * @return list<FormSubmissionInterface>
     */
    public function findAllByForm(FormInterface $form): array
    {
        $this->warmForm($form);

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

    /**
     * When each form last received an answer, in one grouped query: the list
     * shows it on every row, and a form nobody has filled in for months is
     * what that column is there to reveal.
     *
     * @return array<int, string> form id => ISO 8601 date
     */
    public function lastSubmittedByForm(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.form) AS formId', 'MAX(s.submittedAt) AS lastAt')
            ->groupBy('s.form')
            ->getQuery()
            ->getArrayResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row['formId']] = new DateTimeImmutable((string) $row['lastAt'])->format(DATE_ATOM);
        }

        return $dates;
    }
}
