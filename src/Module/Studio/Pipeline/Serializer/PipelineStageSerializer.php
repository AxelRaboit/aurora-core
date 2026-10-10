<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Serializer;

use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PipelineStageSerializerInterface::class)]
class PipelineStageSerializer implements PipelineStageSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(PipelineStageInterface $stage): array
    {
        return [
            'id' => $stage->getId(),
            'name' => $stage->getName(),
            'position' => $stage->getPosition(),
            'colourSlot' => $stage->getColourSlot(),
            'role' => $stage->getRole()?->value,
        ];
    }
}
