<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Entity;

use Aurora\Module\Planning\Event\Enum\PlanningAlertChannelEnum;
use DateTimeImmutable;

interface PlanningEventAlertInterface
{
    public function getId(): ?int;

    public function getEvent(): PlanningEventInterface;

    public function setEvent(PlanningEventInterface $event): static;

    public function getMinutesBefore(): ?int;

    public function setMinutesBefore(int $minutesBefore): static;

    public function setAbsoluteAt(DateTimeImmutable $at): static;

    public function isRelative(): bool;

    public function getChannel(): PlanningAlertChannelEnum;

    public function setChannel(PlanningAlertChannelEnum $channel): static;

    public function getRemindAt(): DateTimeImmutable;

    public function getSentAt(): ?DateTimeImmutable;

    public function markSent(DateTimeImmutable $at): static;

    public function isPending(): bool;
}
