<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Enum;

/**
 * How a publication's thumbnail fills the box a card gives it.
 *
 * The three CSS `object-fit` values that mean something for a picture in a
 * fixed frame. The rest — `none`, `scale-down` — answer questions a card does
 * not ask.
 *
 * Chosen per publication rather than per image: the same photo can be the
 * right crop in one card and the wrong one in another, and it is the pairing
 * that has an answer, not the file.
 */
enum ThumbnailFitEnum: string
{
    /** Fills the frame and crops what does not fit. The usual answer. */
    case Cover = 'cover';

    /**
     * Fits entirely inside the frame, leaving space around it. For a logo or a
     * diagram, where cropping loses the point.
     */
    case Contain = 'contain';

    /** Stretches to the frame, distorting. Rarely right, occasionally asked for. */
    case Fill = 'fill';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The Tailwind class each one renders as. Written out rather than
     * assembled, because Tailwind only emits classes it can read in the source
     * and `object-{{ fit }}` is a class it never sees.
     */
    public function objectFitClass(): string
    {
        return match ($this) {
            self::Cover => 'object-cover',
            self::Contain => 'object-contain',
            self::Fill => 'object-fill',
        };
    }
}
