<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Enum;

/**
 * Who, on the studio side, a note is for.
 *
 * **Neither value is ever shown to the client.** The whole surface is private
 * to the studio - the module has no public controller, and the client's page
 * receives nothing from it. What this column separates is the team from the
 * person: a shared note is the space's memory, a personal one is the author's
 * own, and the second only exists because people write things down before they
 * are ready to say them.
 *
 * A personal note is not merely hidden from the wall: it never leaves the
 * server for anybody but its author. Filtering in the page would have made the
 * tab a display preference, and a display preference is not a confidence.
 *
 * Shared is the default, deliberately. A note taken on a client's space is
 * usually about the work, and a wall nobody else can read is a wall that stops
 * being the space's memory - somebody who wants privacy asks for it.
 *
 * The values are persisted, so they are part of the schema: add and remove,
 * never rename.
 */
enum SpaceNoteVisibilityEnum: string
{
    case Shared = 'shared';

    case Personal = 'personal';

    public function getLabelKey(): string
    {
        return match ($this) {
            self::Shared => 'backend.studio.space_notes.visibilities.shared',
            self::Personal => 'backend.studio.space_notes.visibilities.personal',
        };
    }

    public function isPersonal(): bool
    {
        return self::Personal === $this;
    }
}
