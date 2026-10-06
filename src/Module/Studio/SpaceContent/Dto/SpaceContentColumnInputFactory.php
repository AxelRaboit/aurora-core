<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_numeric;

#[AsAlias(SpaceContentColumnInputFactoryInterface::class)]
class SpaceContentColumnInputFactory implements SpaceContentColumnInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceContentColumnInputInterface
    {
        $slot = $data['colourSlot'] ?? null;

        return new SpaceContentColumnInput(
            name: Str::trimFromArray($data, 'name'),
            // An empty string is what a cleared swatch sends, and it means "no
            // colour" rather than "slot zero".
            colourSlot: is_numeric($slot) ? (int) $slot : null,
            // Only a real `true` shows the stage: a missing or unreadable field
            // leaves it hidden, the space's common rule.
            visibleToClient: true === ($data['visibleToClient'] ?? false),
            // An unknown value is no role rather than an error: the field is
            // optional, and a select cleared by hand sends an empty string.
            role: SpaceContentColumnRoleEnum::tryFrom(Str::trimFromArray($data, 'role')),
        );
    }
}
