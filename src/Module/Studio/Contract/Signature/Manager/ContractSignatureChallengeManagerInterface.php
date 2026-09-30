<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Signature\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureChallengeInterface;
use DateTimeImmutable;

interface ContractSignatureChallengeManagerInterface
{
    /**
     * Mints a code and mails it to the address the contract names.
     *
     * @throws FieldException when this address has asked for too many
     */
    public function issue(ContractAccessLinkInterface $link): ContractSignatureChallengeInterface;

    /**
     * Checks a typed code and consumes it, returning when it was verified.
     *
     * @throws FieldException for every refusal, all worded identically
     */
    public function verify(ContractAccessLinkInterface $link, string $code): DateTimeImmutable;

    /** Where the latest code of the link was sent, read before verifying it. */
    public function recipientOf(ContractAccessLinkInterface $link): ?string;
}
