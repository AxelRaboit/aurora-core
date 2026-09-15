<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Dto;

interface SpaceAccessLinkInputInterface
{
    public function getRecipientEmail(): string;

    public function getLabel(): ?string;

    public function getValidForDays(): int;

    public function canApprove(): bool;

    public function canComment(): bool;
}
