<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Enum;

use function is_string;

/**
 * Who owns a deliverable attached to no client space.
 *
 * Two shelves, as the request drew them: what you prepare for yourself, and
 * what the team shares. No members or roles: a shared deliverable is read
 * and edited by anyone with the module rights, a personal one by its author
 * only.
 *
 * A space deliverable carries `Shared` without using it: the space, and
 * membership of its team, decide who sees it.
 */
enum DeliverableScopeEnum: string
{
    case Personal = 'personal';
    case Shared = 'shared';

    /** What comes from a form; an unknown value stays personal, the cautious choice. */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Personal) : self::Personal;
    }
}
