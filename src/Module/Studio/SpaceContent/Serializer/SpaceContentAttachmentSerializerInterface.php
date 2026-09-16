<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;

interface SpaceContentAttachmentSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceContentAttachmentInterface $attachment): array;
}
