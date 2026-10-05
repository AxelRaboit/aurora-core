<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Enum;

/**
 * Whether a space is still being worked in.
 *
 * Two cases and not three, because "paused" is a thing a person says and not a
 * thing the software can act on: a paused space behaves exactly like an active
 * one, which makes it a note rather than a state. Archived is different - it
 * leaves the default list, and that is a behaviour.
 *
 * Not a soft delete. An archived space keeps its history, its files and its
 * contracts; archiving says the work stopped. Deleting a space is another
 * gesture, which removes it for good along with everything in it.
 *
 * The values are persisted, so they are part of the schema: add and remove,
 * never rename.
 */
enum CustomerSpaceStatusEnum: string
{
    case Active = 'active';

    case Archived = 'archived';

    public function getLabelKey(): string
    {
        return match ($this) {
            self::Active => 'suite.studio.spaces.statuses.active',
            self::Archived => 'suite.studio.spaces.statuses.archived',
        };
    }
}
