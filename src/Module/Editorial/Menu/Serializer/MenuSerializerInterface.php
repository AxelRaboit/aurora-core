<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Serializer;

use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;

interface MenuSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(MenuInterface $menu): array;

    /**
     * @param array<string, array<int, string|null>>|null $targetLabels target type → id → label,
     *                                                                  prefetched by serialize() for a whole
     *                                                                  menu; without it the target is looked up
     *
     * @return array<string, mixed>
     */
    public function serializeItem(MenuItemInterface $item, ?array $targetLabels = null): array;
}
