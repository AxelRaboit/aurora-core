<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Entity;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use DateTimeImmutable;

interface MarkdownNoteShareLinkInterface
{
    public function getId(): ?int;

    public function getToken(): string;

    public function getNote(): MarkdownNoteInterface;

    public function setNote(MarkdownNoteInterface $note): static;

    public function includesDescendants(): bool;

    public function setIncludeDescendants(bool $includeDescendants): static;

    public function includesLinked(): bool;

    public function setIncludeLinked(bool $includeLinked): static;

    public function getRecipientEmail(): ?string;

    public function setRecipientEmail(?string $recipientEmail): static;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    public function getExpiresAt(): ?DateTimeImmutable;

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static;

    public function getRevokedAt(): ?DateTimeImmutable;

    public function revoke(DateTimeImmutable $at): static;

    public function getSentAt(): ?DateTimeImmutable;

    public function markSent(DateTimeImmutable $at): static;

    public function getLastUsedAt(): ?DateTimeImmutable;

    public function setLastUsedAt(?DateTimeImmutable $lastUsedAt): static;

    public function getCreatedAt(): DateTimeImmutable;

    public function isUsableAt(DateTimeImmutable $now): bool;
}
