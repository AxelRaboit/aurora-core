<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Post\Entity\PostSlugHistory;
use Aurora\Module\Editorial\Post\Entity\PostSlugHistoryInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PostSlugHistoryInterface>
 */
class PostSlugHistoryRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostSlugHistory::class, PostSlugHistoryInterface::class);
    }
}
