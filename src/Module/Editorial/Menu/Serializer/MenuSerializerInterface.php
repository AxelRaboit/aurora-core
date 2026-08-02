<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Serializer;

use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;

interface MenuSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(MenuInterface $menu): array;

    /** @return array<string, mixed> */
    public function serializeItem(MenuItemInterface $item): array;
}
