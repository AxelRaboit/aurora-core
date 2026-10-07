<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Enum;

/**
 * The look of a note: its background and the ink that goes with it.
 *
 * **Declared appearances, not a free color.** A color picked with the
 * eyedropper is written as is into a thousand notes: the day the back office
 * background changes, they all keep the old one, and nobody is going to redo
 * them one by one. A named appearance can evolve - you change what it means,
 * and the notes that carry it follow.
 *
 * It is the same trade-off as `DeckThemeEnum`, for the same reason, and the
 * opposite of a folder's color: there, the value only serves to recognize a
 * row in a list, it does not draw a screen.
 *
 * `Plain` is what a note has always been, and stays the default, so that no
 * note changes its look the day the column appears.
 */
enum NoteAppearanceEnum: string
{
    /** The back office background. What every note used to look like. */
    case Plain = 'plain';

    /** Warm paper, brown ink. For reading at length. */
    case Sepia = 'sepia';

    /** Slate. Darker than the rest of the screen, to stand out from it. */
    case Slate = 'slate';

    /** Almost white, black ink. The register of a printed document. */
    case Paper = 'paper';

    /** Deep night, light ink. */
    case Midnight = 'midnight';

    /** Very pale aqua green. */
    case Mint = 'mint';

    /**
     * Reads a value coming from outside without ever failing.
     *
     * An unknown appearance - a note written by a newer version, a tampered
     * payload - is worth the default rather than an exception: a note must
     * always be displayable, even without its look.
     */
    public static function fromNullable(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Plain;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
