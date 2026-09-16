<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentCommentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;

interface SpaceContentCommentManagerInterface
{
    /**
     * A message from the studio, signed by whoever is logged in.
     *
     * Everything written here is read by the client: one shared thread is what
     * makes it a conversation rather than two mailboxes.
     */
    public function postAsStudio(SpaceContentItemInterface $item, string $body): SpaceContentCommentInterface;

    /**
     * A message from the client, signed by the address they hold.
     *
     * The link's right is the caller's to check; what is checked here is that
     * the card belongs to the space that link opens.
     */
    public function postAsClient(
        SpaceContentItemInterface $item,
        SpaceAccessLinkInterface $link,
        string $body,
    ): SpaceContentCommentInterface;

    public function delete(SpaceContentCommentInterface $comment): void;
}
