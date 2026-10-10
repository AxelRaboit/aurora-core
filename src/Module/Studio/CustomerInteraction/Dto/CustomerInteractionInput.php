<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Dto;

use Aurora\Module\Studio\CustomerInteraction\Enum\CustomerInteractionKindEnum;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

class CustomerInteractionInput implements CustomerInteractionInputInterface
{
    public function __construct(
        public readonly CustomerInteractionKindEnum $kind = CustomerInteractionKindEnum::Note,
        #[Assert\NotNull(message: 'suite.studio.customer_interactions.errors.occurred_at_required')]
        public readonly ?DateTimeImmutable $occurredAt = null,
        #[Assert\NotBlank(message: 'suite.studio.customer_interactions.errors.summary_required')]
        #[Assert\Length(max: 5000, maxMessage: 'suite.studio.customer_interactions.errors.summary_too_long')]
        public readonly string $summary = '',
        public readonly bool $setsFollowUp = false,
        public readonly ?DateTimeImmutable $nextFollowUpOn = null,
        #[Assert\Length(max: 255, maxMessage: 'suite.studio.customers.errors.follow_up_note_too_long')]
        public readonly ?string $followUpNote = null,
    ) {}

    public function getKind(): CustomerInteractionKindEnum
    {
        return $this->kind;
    }

    public function getOccurredAt(): ?DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setsFollowUp(): bool
    {
        return $this->setsFollowUp;
    }

    public function getNextFollowUpOn(): ?DateTimeImmutable
    {
        return $this->nextFollowUpOn;
    }

    public function getFollowUpNote(): ?string
    {
        return $this->followUpNote;
    }
}
