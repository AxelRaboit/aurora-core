<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Manager;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMemberInterface;

interface SpaceChatChannelManagerInterface
{
    /**
     * The space's main room, created on the spot if it has none.
     *
     * Every screen that opens a conversation goes through this rather than
     * assuming: a space created before channels existed, or by a fixture that
     * predates them, must not open on an empty list.
     */
    public function ensureMain(CustomerSpaceInterface $space): SpaceChatChannelInterface;

    public function create(CustomerSpaceInterface $space, string $name, bool $openToClient = false): SpaceChatChannelInterface;

    public function rename(SpaceChatChannelInterface $channel, string $name): void;

    public function setOpenToClient(SpaceChatChannelInterface $channel, bool $openToClient): void;

    /** Refuses the main room and private conversations: neither is the studio's to close. */
    public function delete(SpaceChatChannelInterface $channel): void;

    public function invite(SpaceChatChannelInterface $channel, CoreUserInterface $user): SpaceChatChannelMemberInterface;

    public function removeMember(SpaceChatChannelMemberInterface $member): void;

    /**
     * The private conversation between two people, opened if it is their first.
     *
     * Each side is an account or an address, and the pair is looked up before
     * anything is written: asking twice gives the same conversation back, which
     * is what makes the button safe to press from anywhere.
     */
    public function openDirect(
        CustomerSpaceInterface $space,
        ?CoreUserInterface $fromUser,
        ?SpaceAccessLinkInterface $fromLink,
        ?CoreUserInterface $withUser,
        ?SpaceAccessLinkInterface $withLink,
    ): SpaceChatChannelInterface;
}
