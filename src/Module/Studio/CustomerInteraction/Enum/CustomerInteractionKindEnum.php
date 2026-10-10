<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Enum;

/**
 * What kind of exchange was had with a customer.
 *
 * Kept short on purpose: the kind is what the timeline draws an icon from, and
 * a list long enough to need thinking about is a list people skip. Anything
 * that is none of these is a note.
 *
 * The values are persisted: add and remove, never rename.
 */
enum CustomerInteractionKindEnum: string
{
    case Call = 'call';

    case Email = 'email';

    case Meeting = 'meeting';

    case Message = 'message';

    case Note = 'note';

    public function getLabelKey(): string
    {
        return 'suite.studio.customer_interactions.kinds.'.$this->value;
    }
}
