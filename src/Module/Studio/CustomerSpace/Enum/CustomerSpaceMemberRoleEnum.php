<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Enum;

/**
 * What somebody is to a space they are on.
 *
 * Not a permission. Privileges are decided by `studio.spaces.*` on the account,
 * the same way every other screen decides them, and a role here that also
 * granted rights would make two sources disagree about who may edit. This says
 * who to ask and whose name goes on the space, which is the question a list of
 * five people actually has to answer.
 *
 * Persisted values: add and remove, never rename.
 */
enum CustomerSpaceMemberRoleEnum: string
{
    /** The one name a client is given. One per space is the intent, not a constraint. */
    case Lead = 'lead';

    case Member = 'member';

    public function getLabelKey(): string
    {
        return match ($this) {
            self::Lead => 'backend.studio.spaces.roles.lead',
            self::Member => 'backend.studio.spaces.roles.member',
        };
    }
}
