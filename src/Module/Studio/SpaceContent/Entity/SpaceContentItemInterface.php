<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use DateTimeImmutable;

interface SpaceContentItemInterface
{
    public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface;

    public function setSpace(CustomerSpaceInterface $space): static;

    public function getColumn(): SpaceContentColumnInterface;

    public function setColumn(SpaceContentColumnInterface $column): static;

    public function getTitle(): string;

    public function setTitle(string $title): static;

    public function getBody(): ?string;

    public function setBody(?string $body): static;

    public function getScheduledAt(): ?DateTimeImmutable;

    public function setScheduledAt(?DateTimeImmutable $scheduledAt): static;

    public function isShownOnCalendar(): bool;

    public function setShowOnCalendar(bool $showOnCalendar): static;

    public function appearsOnCalendar(): bool;

    public function isScheduled(): bool;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function getApproval(): SpaceContentApprovalEnum;

    public function getApprovalAt(): ?DateTimeImmutable;

    public function getApprovalByLink(): ?SpaceAccessLinkInterface;

    public function answer(
        SpaceContentApprovalEnum $approval,
        SpaceAccessLinkInterface $link,
        DateTimeImmutable $at,
    ): static;

    public function clearApproval(): static;

    public function getCreatedAt(): DateTimeImmutable;

    public function getUpdatedAt(): ?DateTimeImmutable;
}
