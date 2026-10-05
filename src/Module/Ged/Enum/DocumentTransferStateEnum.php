<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Enum;

/**
 * Where a document stands in a move between storage backends.
 *
 * Doubles as the lock. A move is claimed by writing `Pending` only over
 * `Idle`, in one conditional statement, so a second click or a command running
 * at the same time finds the door shut rather than copying the same bytes
 * twice and racing over which deletion wins.
 *
 * Using the displayed state as the lock rather than adding a hidden one is
 * deliberate: two mechanisms saying the same thing eventually disagree, and
 * the one nobody can see is the one that stays wrong.
 */
enum DocumentTransferStateEnum: string
{
    /** Not moving. The overwhelming majority of the time. */
    case Idle = 'idle';

    /** Claimed, and being copied. */
    case Pending = 'pending';

    /**
     * A move gave up. The document is untouched and still readable where it
     * was; what is lost is only the attempt. Kept rather than reset to `Idle`
     * so somebody is told, and so a suite that fails every time does not
     * look like a button that does nothing.
     */
    case Failed = 'failed';
}
