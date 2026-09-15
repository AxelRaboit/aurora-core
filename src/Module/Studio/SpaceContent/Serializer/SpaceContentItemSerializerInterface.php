<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;

interface SpaceContentItemSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceContentItemInterface $item): array;
}
