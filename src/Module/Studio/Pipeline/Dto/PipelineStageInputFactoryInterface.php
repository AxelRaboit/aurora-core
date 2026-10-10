<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Dto;

interface PipelineStageInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PipelineStageInputInterface;
}
