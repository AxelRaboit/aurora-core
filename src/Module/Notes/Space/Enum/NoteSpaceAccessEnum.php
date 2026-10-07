<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Enum;

/**
 * Who has access to a space, in the back office.
 *
 * Independent of publication on the web: a space only one person writes in
 * can be published, a space open to the whole back office may not be.
 */
enum NoteSpaceAccessEnum: string
{
    /** The owner alone. The personal space always is. */
    case Private = 'private';

    /** The members, each with their role. */
    case Members = 'members';

    /**
     * Anybody who has the module, with the space's default role; a member
     * keeps their own if it is stronger.
     */
    case Backoffice = 'backoffice';

    public static function fromInput(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::Private;
    }
}
