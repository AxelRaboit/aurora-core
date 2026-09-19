<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Manager;

use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;

interface SpaceChatMessageManagerInterface
{
    /**
     * A message from the studio, signed by whoever is logged in.
     *
     * Whether the client reads it is the room's answer, not the message's:
     * there is no flag here to forget, because a room is either open to them or
     * it is not, and that is decided once when it is opened rather than on
     * every line somebody types.
     */
    public function postAsStudio(SpaceChatChannelInterface $channel, string $body): SpaceChatMessageInterface;

    /**
     * A message from the client, signed by the address they hold.
     *
     * The link's right is the caller's to check; what is checked here is that
     * the link opens the space it is writing into.
     */
    public function postAsClient(
        SpaceChatChannelInterface $channel,
        SpaceAccessLinkInterface $link,
        string $body,
    ): SpaceChatMessageInterface;

    public function delete(SpaceChatMessageInterface $message): void;
}
