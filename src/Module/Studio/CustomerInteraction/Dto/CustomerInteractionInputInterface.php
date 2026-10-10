<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Dto;

use Aurora\Module\Studio\CustomerInteraction\Enum\CustomerInteractionKindEnum;
use DateTimeImmutable;

interface CustomerInteractionInputInterface
{
    public function getKind(): CustomerInteractionKindEnum;

    public function getOccurredAt(): ?DateTimeImmutable;

    public function getSummary(): string;

    /**
     * Whether this exchange also decides the next follow-up. Only then are
     * the two follow-up fields read: editing an old call must not touch it.
     */
    public function setsFollowUp(): bool;

    public function getNextFollowUpOn(): ?DateTimeImmutable;

    public function getFollowUpNote(): ?string;
}
