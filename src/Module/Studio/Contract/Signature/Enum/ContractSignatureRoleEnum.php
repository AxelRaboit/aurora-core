<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Signature\Enum;

/**
 * Which party a signature belongs to.
 *
 * The two are not symmetric, and the enum is where that starts. A customer
 * signs from a public page with no account, so their identity is established
 * by a code mailed to the address the contract names. The provider signs from
 * the back office, already authenticated, so their identity is the session -
 * a stronger trail, obtained for free.
 *
 * The order is fixed elsewhere: the customer signs first and the provider
 * countersigns, which is what concludes the contract.
 */
enum ContractSignatureRoleEnum: string
{
    case Customer = 'customer';
    case Provider = 'provider';

    public function getLabel(): string
    {
        return 'suite.studio.signatures.role.'.$this->value;
    }

    /**
     * Whether this role has to prove control of a mailbox.
     *
     * Only the customer. Asking an authenticated administrator for a code
     * mailed to themselves would add a step and no evidence.
     */
    public function requiresEmailChallenge(): bool
    {
        return self::Customer === $this;
    }
}
