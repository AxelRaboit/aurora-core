<?php

declare(strict_types=1);

namespace Aurora\Core\Support;

final class Num
{
    private function __construct() {}

    /**
     * Clamp a numeric value into the closed interval `[$min, $max]`.
     * Returns `$min` when `$value < $min`, `$max` when `$value > $max`,
     * otherwise `$value` unchanged. The numeric type of the result
     * follows PHP's normal promotion rules - float in, float out.
     *
     * Replaces the ubiquitous `max($lo, min($hi, $value))` idiom (>10
     * inline sites across the codebase: media cropping, post block
     * columns/level, pagination, image quality, font sizing, …).
     *
     * Behaviour intentionally **not defined** when `$min > $max`: this
     * is a caller bug, not a runtime case to recover from.
     */
    public static function clamp(int|float $value, int|float $min, int|float $max): int|float
    {
        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    /**
     * Convert an integer percentage (0..100, optionally out-of-range)
     * to the [0..1] ratio that browser/canvas/encoder APIs typically
     * expect. Equivalent to `Num::clamp($percent / 100, 0.0, 1.0)` -
     * named for readability at the call site.
     */
    public static function percentToRatio(int $percent): float
    {
        return self::clamp($percent / 100, 0.0, 1.0);
    }
}
