<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Serializer;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;

interface SpaceChatChannelSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceChatChannelInterface $channel): array;

    /**
     * The same room, named from one reader's point of view.
     *
     * Only a private conversation differs: it is called by the person on the
     * other side, and who that is depends on who is looking. A room's name is
     * its name for everybody.
     *
     * Added beside `serialize()` rather than replacing it: the interface is
     * implemented by client projects, and a new argument on an existing method
     * would break every one of them.
     *
     * @return array<string, mixed>
     */
    public function serializeFor(
        SpaceChatChannelInterface $channel,
        ?CoreUserInterface $viewerUser,
        ?SpaceAccessLinkInterface $viewerLink,
    ): array;
}
