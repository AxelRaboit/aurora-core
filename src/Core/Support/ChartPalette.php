<?php

declare(strict_types=1);

namespace Aurora\Core\Support;

/**
 * The shared categorical palette, as a number rather than as colours.
 *
 * Everything that wears one of these colours stores a **slot**, never a hex
 * value. Three reasons, and the first is the one that decides it: a stored
 * colour cannot follow the theme, so one chosen against a white page is
 * whatever it happens to be against a dark one. The eight `--chart-cat-*`
 * tokens have a step per mode for exactly that. They are also already checked
 * for separation under colour-vision deficiency, which a free colour picker
 * cannot promise - `#ff00ff` is a legal answer and an unreadable one.
 *
 * Eight, and a ninth thing shares a colour with one above it rather than
 * getting a generated hue: a generated hue is indistinguishable from an
 * existing one under CVD, which is worse than an honest repeat.
 *
 * **Here because it was in three places.** Planning, client spaces and the
 * board's steps each carried their own copy of the same number, and the JS
 * composables a fourth; the ceiling is a property of the theme's tokens, not of
 * any module that happens to read them. The two entity constants stay, pointing
 * at this, so a client project that referenced them keeps working.
 */
final class ChartPalette
{
    /** Highest slot `src/Core/assets/css/base/chart.css` defines. */
    public const int MAX_SLOT = 8;

    public const int DEFAULT_SLOT = 1;

    /**
     * A slot brought back inside the palette.
     *
     * Clamped rather than rejected: a value out of range is a bug in a caller,
     * not something a person typed, and a colour is not worth failing a save
     * over. Input that a person did type is checked by a DTO constraint, which
     * can say so under the field.
     */
    public static function clamp(int $slot): int
    {
        return max(1, min(self::MAX_SLOT, $slot));
    }

    /** @return list<int> every slot, in order */
    public static function slots(): array
    {
        return range(1, self::MAX_SLOT);
    }
}
