<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Enum;

/**
 * Whether this company has engaged yet.
 *
 * **A prospect is a customer whose legal identity is not known yet**, not a
 * different kind of record. You open a space to work with somebody long before
 * you have their SIRET, and everything downstream - the space, its board, its
 * files, later a contract - hangs off the customer. Two tables would have meant
 * moving all of it on the day they sign, which is the day you least want to be
 * moving rows.
 *
 * So the difference is one column, and converting is flipping it once the
 * identity is filled in.
 *
 * Nothing is forbidden to a prospect. A contract addressed to one is not an
 * error, it is what converting looks like from the other end - and a rule that
 * refused it would only teach people to flip the status first and mean nothing.
 * The single thing the status does enforce is the contractual email, which a
 * client has by definition: it is where their contract is sent.
 *
 * The values are persisted, so they are part of the schema: add and remove,
 * never rename.
 */
enum CustomerStatusEnum: string
{
    case Prospect = 'prospect';

    case Client = 'client';

    public function getLabelKey(): string
    {
        return match ($this) {
            self::Prospect => 'backend.studio.customers.statuses.prospect',
            self::Client => 'backend.studio.customers.statuses.client',
        };
    }

    public function isProspect(): bool
    {
        return self::Prospect === $this;
    }
}
