<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Serializer;

use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;

interface PipelineStageSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(PipelineStageInterface $stage): array;
}
