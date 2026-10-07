<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Audit\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Dev\Audit\Entity\AuditLogInterface;
use DateTimeInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<AuditLogInterface> */
class AuditLogRepository extends ResolveTargetEntityRepository
{
    use PaginationTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class, AuditLogInterface::class);
    }

    public function findPaginated(int $page, int $limit = 50, ?string $module = null): array
    {
        $queryBuilder = $this->createQueryBuilder('a')->orderBy('a.createdAt', Order::Descending->value);
        $countQueryBuilder = $this->createQueryBuilder('a')->select('COUNT(a.id)');

        if (null !== $module) {
            $queryBuilder->andWhere('a.module = :module')->setParameter('module', $module);
            $countQueryBuilder->andWhere('a.module = :module')->setParameter('module', $module);
        }

        return $this->paginate($queryBuilder, $countQueryBuilder, $page, $limit);
    }

    public function findPaginatedForEntity(string $entityType, int $entityId, int $page, int $limit = 10): array
    {
        $queryBuilder = $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :type')->setParameter('type', $entityType)
            ->andWhere('a.entityId = :id')->setParameter('id', $entityId)
            ->orderBy('a.createdAt', Order::Descending->value);

        $countQueryBuilder = $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.entityType = :type')->setParameter('type', $entityType)
            ->andWhere('a.entityId = :id')->setParameter('id', $entityId);

        return $this->paginate($queryBuilder, $countQueryBuilder, $page, $limit);
    }

    /**
     * Billing anomalies: invoice.updated actions where the user is null (system
     * edits bypassing the normal workflow) or edits on Validated/Paid invoices
     * that lack a preceding invoice.validated event. Returns last 100 suspicious
     * entries for display in the compliance screen.
     *
     * @return list<array{id: int, action: string, entityId: ?int, createdAt: string, userName: ?string, data: mixed}>
     */
    public function findBillingAnomalies(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->where('a.module = :module')
            ->andWhere('a.action IN (:actions)')
            ->andWhere('a.userName IS NULL')
            ->setParameter('module', 'billing')
            ->setParameter('actions', ['invoice.updated', 'invoice.line.updated', 'invoice.line.added', 'invoice.line.deleted'])
            ->orderBy('a.createdAt', Order::Descending->value)
            ->setMaxResults(100)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'action' => $row['action'],
            'entityId' => $row['entityId'],
            'createdAt' => $row['createdAt']->format(DateTimeInterface::ATOM),
            'userName' => $row['userName'],
            'data' => $row['data'],
        ], $rows);
    }

    /**
     * Project-specific timeline: every audit row that targets the project itself,
     * one of its tasks, or one of its columns.
     *
     * @param int[] $taskIds
     * @param int[] $columnIds
     *
     * @return list<AuditLog>
     */
    public function findForProject(int $projectId, array $taskIds, array $columnIds, int $limit = 50): array
    {
        $queryBuilder = $this->createQueryBuilder('a')
            ->andWhere(
                '(a.entityType = :projectType AND a.entityId = :projectId)'
                .([] !== $taskIds ? ' OR (a.entityType = :taskType AND a.entityId IN (:taskIds))' : '')
                .([] !== $columnIds ? ' OR (a.entityType = :columnType AND a.entityId IN (:columnIds))' : ''),
            )
            ->setParameter('projectType', 'Project')
            ->setParameter('projectId', $projectId)
            ->orderBy('a.createdAt', Order::Descending->value)
            ->setMaxResults($limit);

        if ([] !== $taskIds) {
            $queryBuilder->setParameter('taskType', 'ProjectTask')->setParameter('taskIds', $taskIds);
        }

        if ([] !== $columnIds) {
            $queryBuilder->setParameter('columnType', 'ProjectColumn')->setParameter('columnIds', $columnIds);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /** @return array<int, string> */
    public function findDistinctModules(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.module')
            ->orderBy('a.module', Order::Ascending->value)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['module'], $rows);
    }
}
