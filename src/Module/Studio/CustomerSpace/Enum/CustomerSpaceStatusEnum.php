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
 * Not a soft delete. A space nobody deleted still has its history, its files
 * and its contracts; archiving says the work stopped, and `deletedAt` says
 * somebody threw it away. The two answer different questions and a single
 * column would have had to answer both.
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
            self::Active => 'backend.studio.spaces.statuses.active',
            self::Archived => 'backend.studio.spaces.statuses.archived',
        };
    }
}
