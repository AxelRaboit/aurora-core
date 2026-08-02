<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Contract;

use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Enum\MenuItemVisibilityEnum;

/**
 * An entry a fresh install should find in a menu.
 *
 * The `reference` is what makes the sync idempotent and what makes editing
 * safe: it identifies the entry across runs, so a second sync recognises the
 * one it created before instead of adding a duplicate — and an admin who
 * renamed or moved it keeps their version.
 *
 * Defaults never point at content. A post's id does not exist at install
 * time, and guessing one by slug would seed a menu entry that breaks the
 * day someone renames the post it guessed at.
 */
final readonly class DefaultMenuItem
{
    /** @param list<self> $children */
    public function __construct(
        public string $reference,
        public string $labelKey,
        public MenuItemTargetTypeEnum $targetType,
        public ?string $customUrl = null,
        public MenuItemVisibilityEnum $visibility = MenuItemVisibilityEnum::Always,
        public array $children = [],
    ) {}
}
