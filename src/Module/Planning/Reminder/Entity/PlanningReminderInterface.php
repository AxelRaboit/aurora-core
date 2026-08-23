<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;

interface PlanningReminderInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getPlanning(): PlanningInterface;

    public function setPlanning(PlanningInterface $planning): static;

    public function getTitle(): string;

    public function setTitle(string $title): static;

    public function getNotes(): ?string;

    public function setNotes(?string $notes): static;

    public function getDueAt(): DateTimeImmutable;

    public function setDueAt(DateTimeImmutable $dueAt): static;

    public function isAllDay(): bool;

    public function setAllDay(bool $allDay): static;

    public function getCompletedAt(): ?DateTimeImmutable;

    public function isCompleted(): bool;

    public function complete(DateTimeImmutable $at): static;

    public function reopen(): static;

    public function getNotifiedAt(): ?DateTimeImmutable;

    public function markNotified(DateTimeImmutable $at): static;
}
