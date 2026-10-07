<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Module\Platform\Auth\Entity\AccessRequest;
use Aurora\Module\Platform\Auth\Entity\AccessRequestInterface;
use Aurora\Module\Platform\Auth\Enum\AccessRequestStatusEnum;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<AccessRequestInterface>
 */
class AccessRequestRepository extends ResolveTargetEntityRepository
{
    use PaginationTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequest::class, AccessRequestInterface::class);
    }

    public function findByToken(string $token): ?AccessRequestInterface
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function deleteProcessed(): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.status IN (:statuses)')
            ->setParameter('statuses', [AccessRequestStatusEnum::Approved, AccessRequestStatusEnum::Rejected])
            ->getQuery()
            ->execute();
    }

    /**
     * @return array{items: AccessRequest[], total: int, page: int, totalPages: int}
     */
    public function findPaginatedAdmin(int $page = 1, int $limit = 20, ?string $search = null): array
    {
        $queryBuilder = $this->createQueryBuilder('a')->orderBy('a.createdAt', Order::Descending->value);
        $countQueryBuilder = $this->createQueryBuilder('a')->select('COUNT(a.id)');

        if (null !== $search && '' !== mb_trim($search)) {
            $term = '%'.mb_trim($search).'%';
            foreach ([$queryBuilder, $countQueryBuilder] as $builder) {
                $builder->andWhere('a.requesterEmail LIKE :search OR a.requesterName LIKE :search OR a.message LIKE :search')
                    ->setParameter('search', $term);
            }
        }

        return $this->paginate($queryBuilder, $countQueryBuilder, $page, $limit);
    }
}
