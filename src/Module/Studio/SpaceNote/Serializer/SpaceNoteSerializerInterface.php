<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Serializer;

use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;

interface SpaceNoteSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceNoteInterface $note): array;
}
