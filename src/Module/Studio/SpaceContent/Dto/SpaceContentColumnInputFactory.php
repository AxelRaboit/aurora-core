<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

use Aurora\Core\Support\Str;
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
        );
    }
}
