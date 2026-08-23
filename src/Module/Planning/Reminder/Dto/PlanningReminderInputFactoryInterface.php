<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Dto;

interface PlanningReminderInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PlanningReminderInputInterface;
}
