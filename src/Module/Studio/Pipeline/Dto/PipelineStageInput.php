<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Dto;

use Aurora\Core\Support\ChartPalette;
use Aurora\Module\Studio\Pipeline\Enum\PipelineStageRoleEnum;
use Symfony\Component\Validator\Constraints as Assert;

class PipelineStageInput implements PipelineStageInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.studio.pipeline.errors.stage_name_required')]
        #[Assert\Length(max: 100, maxMessage: 'suite.studio.pipeline.errors.stage_name_too_long')]
        public readonly string $name = '',
        #[Assert\Range(min: 1, max: ChartPalette::MAX_SLOT)]
        public readonly ?int $colourSlot = null,
        public readonly ?PipelineStageRoleEnum $role = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    public function getRole(): ?PipelineStageRoleEnum
    {
        return $this->role;
    }
}
