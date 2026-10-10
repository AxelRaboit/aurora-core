<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Studio\CustomerInteraction\Enum\CustomerInteractionKindEnum;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CustomerInteractionInputFactoryInterface::class)]
class CustomerInteractionInputFactory implements CustomerInteractionInputFactoryInterface
{
    public function __construct(
        protected readonly SiteTimezone $siteTimezone,
    ) {}

    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CustomerInteractionInputInterface
    {
        $followUpOn = Str::trimFromArray($data, 'nextFollowUpOn');
        $followUpDate = '' === $followUpOn ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $followUpOn);

        return new CustomerInteractionInput(
            kind: CustomerInteractionKindEnum::tryFrom(Str::trimFromArray($data, 'kind')) ?? CustomerInteractionKindEnum::Note,
            // Typed at the studio's time ("2026-10-10T14:30"), stored in UTC.
            occurredAt: $this->siteTimezone->parseLocal(Str::trimOrNullFromArray($data, 'occurredAt')),
            summary: Str::trimFromArray($data, 'summary'),
            setsFollowUp: true === ($data['setsFollowUp'] ?? false),
            nextFollowUpOn: false === $followUpDate ? null : $followUpDate,
            followUpNote: Str::trimOrNullFromArray($data, 'followUpNote'),
        );
    }
}
