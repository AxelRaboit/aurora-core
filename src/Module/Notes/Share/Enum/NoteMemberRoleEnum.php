<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Enum;

/**
 * What somebody a single note was shared with can do to it.
 *
 * **Two cases, where a space has three.** A space's third role, manager,
 * exists to empty a trash, configure the space and manage its members; none
 * of those is a thing you do to one note. Reusing the space's enum here
 * would have carried a case that grants nothing, and a value that means
 * nothing is a value somebody will eventually store.
 */
enum NoteMemberRoleEnum: string
{
    /** Read the note, follow its links inside what they may read, export it. */
    case Reader = 'reader';

    /**
     * Plus write its text: title, body, tags, banner.
     *
     * Not where it lives. Filing it, moving it, trashing it, erasing it or
     * making a template of it stay with the space that holds it - see
     * `NoteSpaceAccess::canAdministerNote()`.
     */
    case Editor = 'editor';

    public function canWrite(): bool
    {
        return self::Editor === $this;
    }

    public static function fromInput(mixed $value, self $default = self::Reader): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? $default;
    }
}
