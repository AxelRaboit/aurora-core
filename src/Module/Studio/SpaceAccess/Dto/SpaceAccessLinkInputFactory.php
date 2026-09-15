<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManager;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_numeric;

#[AsAlias(SpaceAccessLinkInputFactoryInterface::class)]
class SpaceAccessLinkInputFactory implements SpaceAccessLinkInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceAccessLinkInputInterface
    {
        $days = $data['validForDays'] ?? null;

        return new SpaceAccessLinkInput(
            recipientEmail: Str::emailFromArray($data, 'recipientEmail'),
            label: Str::trimOrNullFromArray($data, 'label'),
            validForDays: is_numeric($days) ? (int) $days : SpaceAccessLinkManager::DEFAULT_VALID_DAYS,
        );
    }
}
