<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Dto;

use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use DateTimeImmutable;

interface PlanningEventInputInterface
{
    public function getPlanningId(): int;

    public function getTitle(): string;

    public function getDescription(): ?string;

    public function getLocation(): ?string;

    public function getStartAt(): DateTimeImmutable;

    public function getEndAt(): DateTimeImmutable;

    public function isAllDay(): bool;

    public function getStatus(): PlanningEventStatusEnum;

    /** @return list<array{minutes: int|null, at: DateTimeImmutable|null}> */
    public function getAlerts(): array;
}
