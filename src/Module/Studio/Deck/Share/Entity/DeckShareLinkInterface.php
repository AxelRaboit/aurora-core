<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Share\Entity;

use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use DateTimeImmutable;

interface DeckShareLinkInterface
{
    public function getId(): ?int;

    public function getToken(): string;

    public function getDeck(): DeckInterface;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    public function getExpiresAt(): ?DateTimeImmutable;

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static;

    public function getRevokedAt(): ?DateTimeImmutable;

    public function revoke(DateTimeImmutable $at): static;

    public function getLastUsedAt(): ?DateTimeImmutable;

    public function touch(DateTimeImmutable $at): static;

    public function getCreatedAt(): DateTimeImmutable;

    public function isUsable(DateTimeImmutable $now): bool;

    public function getOpenCount(): int;

    public function isLocked(): bool;

    public function getPasswordHash(): ?string;

    public function setPasswordHash(?string $passwordHash): static;
}
