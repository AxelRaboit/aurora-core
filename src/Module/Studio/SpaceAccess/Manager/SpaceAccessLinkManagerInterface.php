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
    ): SpaceAccessLinkInterface;

    public function revoke(SpaceAccessLinkInterface $link): void;

    public function delete(SpaceAccessLinkInterface $link): void;

    /**
     * The link a selector and a secret open, or null.
     *
     * Null for every reason: unknown selector, wrong secret, revoked, expired.
     * The caller renders one page for all of them, because telling a stranger
     * which of those it was tells them which guesses landed.
     */
    public function resolveUsable(string $selector, string $token): ?SpaceAccessLinkInterface;

    public function markOpened(SpaceAccessLinkInterface $link): void;
}
