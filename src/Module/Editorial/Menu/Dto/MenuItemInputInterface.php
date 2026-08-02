<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Dto;

use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Enum\MenuItemVisibilityEnum;

interface MenuItemInputInterface
{
    /** @return array<string, array{label: ?string}> */
    public function getTranslations(): array;

    public function getTargetType(): MenuItemTargetTypeEnum;

    public function getTargetId(): ?int;

    public function getCustomUrl(): ?string;

    public function isOpenInNewTab(): bool;

    public function getCssClass(): ?string;

    public function getVisibility(): MenuItemVisibilityEnum;

    public function getParentId(): ?int;
}
