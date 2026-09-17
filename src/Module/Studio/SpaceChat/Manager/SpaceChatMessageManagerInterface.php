<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Manager;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;

interface SpaceChatMessageManagerInterface
{
    /**
     * A message from the studio, signed by whoever is logged in.
     *
     * Everything written here is read by the client. There is no internal side
     * to this conversation, deliberately: a flag one forgets once is worse than
     * not having one.
     */
    public function postAsStudio(CustomerSpaceInterface $space, string $body): SpaceChatMessageInterface;

    /**
     * A message from the client, signed by the address they hold.
     *
     * The link's right is the caller's to check; what is checked here is that
     * the link opens the space it is writing into.
     */
    public function postAsClient(
        CustomerSpaceInterface $space,
        SpaceAccessLinkInterface $link,
        string $body,
    ): SpaceChatMessageInterface;

    public function delete(SpaceChatMessageInterface $message): void;
}
