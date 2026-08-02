<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Menu\Entity\MenuItemTranslation;
use Aurora\Module\Editorial\Menu\Entity\MenuItemTranslationInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<MenuItemTranslationInterface>
 */
class MenuItemTranslationRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuItemTranslation::class, MenuItemTranslationInterface::class);
    }
}
