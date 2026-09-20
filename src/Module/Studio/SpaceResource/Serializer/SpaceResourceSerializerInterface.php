<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Serializer;

use Aurora\Module\Studio\SpaceResource\Entity\SpaceResourceInterface;

interface SpaceResourceSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceResourceInterface $resource): array;

    /**
     * La même ressource, telle que le client la reçoit.
     *
     * @return array<string, mixed>
     */
    public function serializeForGuest(SpaceResourceInterface $resource): array;
}
