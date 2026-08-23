<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Planning\Planning\Entity\AbstractPlanning;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PlanningInputFactoryInterface::class)]
class PlanningInputFactory implements PlanningInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PlanningInputInterface
    {
        return new PlanningInput(
            name: Str::trimFromArray($data, 'name'),
            description: Str::trimOrNullFromArray($data, 'description'),
            colourSlot: is_numeric($data['colourSlot'] ?? null)
                ? (int) $data['colourSlot']
                : AbstractPlanning::DEFAULT_COLOUR_SLOT,
            timezone: '' !== Str::trimFromArray($data, 'timezone')
                ? Str::trimFromArray($data, 'timezone')
                : 'Europe/Paris',
            // An unknown value lands on the safe end rather than raising: a
            // payload from an older client should not be able to publish a
            // calendar by sending nonsense.
            visibility: PlanningVisibilityEnum::tryFrom((string) ($data['visibility'] ?? ''))
                ?? PlanningVisibilityEnum::Private,
        );
    }
}
