<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Serializer;

use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;

interface SpaceChatChannelSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceChatChannelInterface $channel): array;
}
