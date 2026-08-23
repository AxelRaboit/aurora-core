<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Entity;

use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface PlanningEventInterface
{
    public function getId(): ?int;

    public function getTitle(): string;

    public function setTitle(string $title): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    public function getLocation(): ?string;

    public function setLocation(?string $location): static;

    public function getStartAt(): DateTimeImmutable;

    public function getEndAt(): DateTimeImmutable;

    public function setSpan(DateTimeImmutable $startAt, DateTimeImmutable $endAt): static;

    public function isAllDay(): bool;

    public function setAllDay(bool $allDay): static;

    public function getStatus(): PlanningEventStatusEnum;

    public function setStatus(PlanningEventStatusEnum $status): static;

    public function getPlanning(): PlanningInterface;

    public function setPlanning(PlanningInterface $planning): static;

    public function getSourceType(): ?string;

    public function getSourceId(): ?int;

    public function getSourceLabel(): ?string;

    public function setSource(?string $sourceType, ?int $sourceId, ?string $sourceLabel): static;

    public function getSourceUrl(): ?string;

    public function setSourceUrl(?string $sourceUrl): static;

    public function isFromModule(): bool;

    /**
     * @return Collection<int, PlanningEventReminderInterface>
     */
    public function getReminders(): Collection;

    public function addReminder(PlanningEventReminderInterface $reminder): static;

    public function removeReminder(PlanningEventReminderInterface $reminder): static;
}
