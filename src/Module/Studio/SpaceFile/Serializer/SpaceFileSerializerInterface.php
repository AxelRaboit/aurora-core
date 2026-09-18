<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Serializer;

use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFileInterface;

interface SpaceFileSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceFileInterface $file): array;

    /** @return array<string, mixed> */
    public function serializeForGuest(SpaceFileInterface $file, SpaceAccessLinkInterface $link, string $token): array;
}
