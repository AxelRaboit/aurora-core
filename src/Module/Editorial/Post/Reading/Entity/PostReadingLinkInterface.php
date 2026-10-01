<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Reading\Entity;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use DateTimeImmutable;

interface PostReadingLinkInterface
{
    public function getId(): ?int;

    public function getToken(): string;

    public function getPost(): PostInterface;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    public function getExpiresAt(): ?DateTimeImmutable;

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static;

    public function getRevokedAt(): ?DateTimeImmutable;

    public function revoke(DateTimeImmutable $at): static;

    public function getLastUsedAt(): ?DateTimeImmutable;

    /** One more opening, at this moment. */
    public function touch(DateTimeImmutable $at): static;

    public function getOpenCount(): int;

    public function isLocked(): bool;

    public function setPasswordHash(?string $passwordHash): static;

    public function getPasswordHash(): ?string;

    public function getCreatedAt(): DateTimeImmutable;

    /** Neither revoked nor past its date. Says nothing of the publication behind it. */
    public function isUsable(DateTimeImmutable $now): bool;
}
