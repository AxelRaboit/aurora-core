<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Studio\Pipeline\Enum\PipelineStageRoleEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_numeric;

#[AsAlias(PipelineStageInputFactoryInterface::class)]
class PipelineStageInputFactory implements PipelineStageInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PipelineStageInputInterface
    {
        $slot = $data['colourSlot'] ?? null;

        return new PipelineStageInput(
            name: Str::trimFromArray($data, 'name'),
            // An empty string is what a cleared swatch sends, and it means "no
            // colour" rather than "slot zero".
            colourSlot: is_numeric($slot) ? (int) $slot : null,
            // An unknown value is no role rather than an error: a select
            // cleared by hand sends an empty string.
            role: PipelineStageRoleEnum::tryFrom(Str::trimFromArray($data, 'role')),
        );
    }
}
