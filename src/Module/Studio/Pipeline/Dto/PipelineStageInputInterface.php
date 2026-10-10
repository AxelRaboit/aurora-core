<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Dto;

use Aurora\Module\Studio\Pipeline\Enum\PipelineStageRoleEnum;

interface PipelineStageInputInterface
{
    public function getName(): string;

    /** Null means the stage wears no colour, which is a choice and not an absence. */
    public function getColourSlot(): ?int;

    public function getRole(): ?PipelineStageRoleEnum;
}
