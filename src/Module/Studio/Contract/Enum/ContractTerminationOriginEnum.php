<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Enum;

/**
 * Who ended the contract.
 *
 * Recorded because the three cases are not the same conversation: a customer
 * leaving, a provider stopping, and both agreeing are read differently a year
 * later, and the notice period only belongs to the first two.
 */
enum ContractTerminationOriginEnum: string
{
    /** The customer gave notice. */
    case Customer = 'customer';

    /** The provider gave notice. */
    case Provider = 'provider';

    /** Both agreed to stop, usually with a document to that effect. */
    case Mutual = 'mutual';

    public function getLabel(): string
    {
        return 'suite.studio.contracts.termination.origin.'.$this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $origin): string => $origin->value, self::cases());
    }
}
