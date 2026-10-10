<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Manager;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;

interface SpaceAccessLinkManagerInterface
{
    /**
     * Mints an address for one person, and returns it with its secret readable
     * exactly once - the only moment it exists in a form anybody can copy.
     */
    public function issue(
        CustomerSpaceInterface $space,
        string $recipientEmail,
        ?string $label,
        int $validForDays,
        bool $canApprove,
        bool $canComment,
        bool $canChat = true,
        bool $canUpload = false,
        bool $canSeeDrive = true,
        bool $canSeeContracts = false,
    ): SpaceAccessLinkInterface;

    /**
     * A preview of this link, valid for a few minutes and unable to write.
     *
     * The clear-text token of the returned link can be read only once, as for
     * any freshly issued link.
     */
    public function preview(SpaceAccessLinkInterface $source): SpaceAccessLinkInterface;

    public function revoke(SpaceAccessLinkInterface $link): void;

    /** Gives a link the default validity again, from today. A revoked link stays closed. */
    public function extend(SpaceAccessLinkInterface $link): void;

    public function delete(SpaceAccessLinkInterface $link): void;

    /**
     * The link a selector and a secret open, or null.
     *
     * Null for every reason: unknown selector, wrong secret, revoked, expired.
     * The caller renders one page for all of them, because telling a stranger
     * which of those it was tells them which guesses landed.
     */
    public function resolveUsable(string $selector, string $token): ?SpaceAccessLinkInterface;

    /** Gives the link a short address and returns its name. */
    public function giveAlias(SpaceAccessLinkInterface $link, string $name): string;

    public function removeAlias(SpaceAccessLinkInterface $link): void;

    /** The usable link a short address opens, or null. */
    public function resolveAlias(string $alias): ?SpaceAccessLinkInterface;

    /** The token the short address redirects with, or null without one. */
    public function aliasToken(SpaceAccessLinkInterface $link): ?string;

    /** The token the application's own mails open this link with, or null without a secret. */
    public function mailToken(SpaceAccessLinkInterface $link): ?string;

    public function markOpened(SpaceAccessLinkInterface $link): void;
}
