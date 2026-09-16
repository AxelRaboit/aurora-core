<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(SpaceContentColumnSerializerInterface::class)]
class SpaceContentColumnSerializer implements SpaceContentColumnSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceContentColumnInterface $column): array
    {
        return [
            'id' => $column->getId(),
            'name' => $column->getName(),
            'position' => $column->getPosition(),
            'colourSlot' => $column->getColourSlot(),
        ];
    }
}
