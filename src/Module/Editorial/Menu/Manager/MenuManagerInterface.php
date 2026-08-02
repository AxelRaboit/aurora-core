<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Manager;

use Aurora\Module\Editorial\Menu\Dto\MenuInputInterface;
use Aurora\Module\Editorial\Menu\Dto\MenuItemInputInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;

interface MenuManagerInterface
{
    public function update(MenuInterface $menu, MenuInputInterface $input): void;

    public function createItem(MenuInterface $menu, MenuItemInputInterface $input): MenuItemInterface;

    public function updateItem(MenuItemInterface $item, MenuItemInputInterface $input): void;

    public function deleteItem(MenuItemInterface $item): void;

    /** @param list<array{id: int, parentId: ?int, position: int}> $entries */
    public function reorderItems(MenuInterface $menu, array $entries): void;
}
