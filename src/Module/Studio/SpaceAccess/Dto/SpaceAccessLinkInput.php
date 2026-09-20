<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Dto;

use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManager;
use Symfony\Component\Validator\Constraints as Assert;

class SpaceAccessLinkInput implements SpaceAccessLinkInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.space_access.errors.email_required')]
        #[Assert\Email(message: 'backend.studio.space_access.errors.email_invalid')]
        #[Assert\Length(max: 180)]
        public readonly string $recipientEmail = '',
        #[Assert\Length(max: 120)]
        public readonly ?string $label = null,
        // A ceiling rather than a free number: past a year nobody is choosing a
        // duration, they are avoiding one. The Manager clamps as well, because
        // it is also called by fixtures and commands that have no DTO.
        #[Assert\Range(min: 1, max: SpaceAccessLinkManager::MAX_VALID_DAYS)]
        public readonly int $validForDays = SpaceAccessLinkManager::DEFAULT_VALID_DAYS,
        // True by default: getting an answer is what a link is usually for.
        // False is for the second reader - a colleague of the client, a partner
        // agency - who is shown the plan and does not decide on it.
        public readonly bool $canApprove = true,
        public readonly bool $canComment = true,
        public readonly bool $canUpload = false,
        public readonly bool $canSeeDrive = true,
    ) {}

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getValidForDays(): int
    {
        return $this->validForDays;
    }

    public function canApprove(): bool
    {
        return $this->canApprove;
    }

    public function canComment(): bool
    {
        return $this->canComment;
    }

    public function canUpload(): bool
    {
        return $this->canUpload;
    }

    public function canSeeDrive(): bool
    {
        return $this->canSeeDrive;
    }
}
