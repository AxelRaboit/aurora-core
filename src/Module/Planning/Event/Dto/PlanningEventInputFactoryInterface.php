<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Dto;

interface PlanningEventInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PlanningEventInputInterface;
}
