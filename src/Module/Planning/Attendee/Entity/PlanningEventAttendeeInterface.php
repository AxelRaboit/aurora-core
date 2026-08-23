<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Attendee\Entity;

use Aurora\Module\Planning\Attendee\Enum\PlanningAttendeeStatusEnum;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface PlanningEventAttendeeInterface
{
    public function getId(): ?int;

    public function getEvent(): PlanningEventInterface;

    public function setEvent(PlanningEventInterface $event): static;

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function getStatus(): PlanningAttendeeStatusEnum;

    public function respond(PlanningAttendeeStatusEnum $status, DateTimeImmutable $at): static;

    public function getRespondedAt(): ?DateTimeImmutable;
}
