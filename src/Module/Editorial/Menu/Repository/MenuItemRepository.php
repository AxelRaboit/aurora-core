<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<MenuItemInterface>
 */
class MenuItemRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuItem::class, MenuItemInterface::class);
    }

    /**
     * References identify the entries the bootstrap creates, so re-running it
     * recognises its own work instead of duplicating it.
     */
    public function findOneByReference(string $reference): ?MenuItemInterface
    {
        return $this->findOneBy(['reference' => $reference]);
    }
}
