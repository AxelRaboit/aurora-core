<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Post\Entity\PostTranslation;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PostTranslationInterface>
 */
class PostTranslationRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostTranslation::class, PostTranslationInterface::class);
    }
}
