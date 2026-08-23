<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Dto;

use DateTimeImmutable;

interface PlanningReminderInputInterface
{
    public function getPlanningId(): int;

    public function getTitle(): string;

    public function getNotes(): ?string;

    public function getDueAt(): DateTimeImmutable;

    public function isAllDay(): bool;

    public function isCompleted(): bool;
}
