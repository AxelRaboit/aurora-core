<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Dto;

interface PlanningInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PlanningInputInterface;
}
