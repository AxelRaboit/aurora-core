<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Enum;

/**
 * The typeface a note is read in (09/10/2026), as Notion offers it: the
 * interface's own, a serif for long reading, a monospace for technical notes.
 *
 * Named faces rather than a free font: the note follows the house's choices
 * when they change, the same reasoning as {@see NoteAppearanceEnum}.
 */
enum NoteFontEnum: string
{
    case Sans = 'sans';

    case Serif = 'serif';

    case Mono = 'mono';

    /** An unknown value reads as the default: a note always displays. */
    public static function fromNullable(?string $value): self
    {
        return null === $value ? self::Sans : (self::tryFrom($value) ?? self::Sans);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
