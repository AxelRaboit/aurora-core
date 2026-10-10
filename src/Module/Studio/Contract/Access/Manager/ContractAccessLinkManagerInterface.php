<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Access\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;

interface ContractAccessLinkManagerInterface
{
    /**
     * Mints an address, revokes any earlier one, and mails it.
     *
     * @throws FieldException when the contract is not sealed, or already signed
     */
    public function send(ContractInterface $contract): ContractAccessLinkInterface;

    /**
     * Chases an unsigned contract: the same hand-out as a resend, with the
     * reminder's own mail, and the contract's counter moved on.
     */
    public function remind(ContractInterface $contract): ContractAccessLinkInterface;

    public function revoke(ContractAccessLinkInterface $link): void;

    /** Marks as expired the contracts whose last address has run out; returns how many. */
    public function expireLapsed(): int;

    /** The link a selector and a secret name, or null for every kind of failure. */
    public function resolveUsable(string $selector, string $token): ?ContractAccessLinkInterface;

    public function markOpened(ContractAccessLinkInterface $link): void;

    public function urlFor(ContractAccessLinkInterface $link, string $token): string;

    /** The token a client space opens this link with, or null without a secret. */
    public function spaceToken(ContractAccessLinkInterface $link): ?string;
}
