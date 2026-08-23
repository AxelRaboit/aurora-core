<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Dto;

use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;

interface PlanningInputInterface
{
    public function getName(): string;

    public function getDescription(): ?string;

    public function getColourSlot(): int;

    public function getTimezone(): string;

    public function getVisibility(): PlanningVisibilityEnum;
}
