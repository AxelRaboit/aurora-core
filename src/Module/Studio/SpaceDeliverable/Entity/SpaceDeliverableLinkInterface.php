<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\Entity;

use DateTimeImmutable;

interface SpaceDeliverableLinkInterface
{
    public function getId(): ?int;

    public function getToken(): string;

    public function getDeliverable(): SpaceDeliverableInterface;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    public function getExpiresAt(): ?DateTimeImmutable;

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static;

    public function getRevokedAt(): ?DateTimeImmutable;

    public function revoke(DateTimeImmutable $at): static;

    public function getLastUsedAt(): ?DateTimeImmutable;

    public function touch(DateTimeImmutable $at): static;

    public function getOpenCount(): int;

    public function isLocked(): bool;

    public function setPasswordHash(?string $passwordHash): static;

    public function getPasswordHash(): ?string;

    public function getCreatedAt(): DateTimeImmutable;

    public function isUsable(DateTimeImmutable $now): bool;
}
