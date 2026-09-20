<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Entity;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use DateTimeImmutable;

interface SpaceAccessLinkInterface
{
    public function getId(): ?int;

    public function mint(): string;

    public function getSelector(): string;

    public function getHashedToken(): string;

    public function getPlainToken(): ?string;

    public function getSpace(): CustomerSpaceInterface;

    public function setSpace(CustomerSpaceInterface $space): static;

    public function getRecipientEmail(): string;

    public function setRecipientEmail(string $recipientEmail): static;

    public function getLabel(): ?string;

    public function setLabel(?string $label): static;

    public function getExpiresAt(): DateTimeImmutable;

    public function setExpiresAt(DateTimeImmutable $expiresAt): static;

    public function getRevokedAt(): ?DateTimeImmutable;

    public function revoke(DateTimeImmutable $at): static;

    public function canApprove(): bool;

    public function setCanApprove(bool $canApprove): static;

    public function canComment(): bool;

    public function setCanComment(bool $canComment): static;

    public function getPreviewOf(): ?self;

    public function setPreviewOf(?self $previewOf): static;

    public function isPreview(): bool;

    public function canSeeDrive(): bool;

    public function setCanSeeDrive(bool $canSeeDrive): static;

    public function canUpload(): bool;

    public function setCanUpload(bool $canUpload): static;

    public function getFirstOpenedAt(): ?DateTimeImmutable;

    public function getLastUsedAt(): ?DateTimeImmutable;

    public function markUsed(DateTimeImmutable $at): static;

    public function getCreatedAt(): DateTimeImmutable;

    public function isRevoked(): bool;

    public function isExpired(DateTimeImmutable $now): bool;

    public function isUsable(DateTimeImmutable $now): bool;
}
