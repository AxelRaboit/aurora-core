<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Serializer;

use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;

interface SpaceChatMessageSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceChatMessageInterface $message): array;
}
