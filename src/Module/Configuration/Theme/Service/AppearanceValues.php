<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Service;

use function in_array;
use function is_string;
use function mb_trim;
use function preg_match;

/**
 * How a colour or a hover mode chosen for ONE page is read on the way in, the
 * same for a publication and for a deliverable.
 *
 * These values end up in a public `<style>`: a colour that is not exactly
 * `#RRGGBB` would be arbitrary CSS there. Two copies of this check had grown,
 * one in the publications' input factory and one in the deliverables'
 * appearance, and a rule that lives twice gets fixed once. A value that does
 * not pass becomes null, and null means "the theme's own", never "none".
 */
final class AppearanceValues
{
    private function __construct() {}

    /** A colour as `#RRGGBB`, trimmed, or null. */
    public static function color(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $value = mb_trim($raw);

        return 1 === preg_match(ThemeContext::HEX_COLOR, $value) ? $value : null;
    }

    /**
     * A hover mode among {@see ThemeContext::HIGHLIGHTS}, or null to inherit the
     * theme's. An unknown mode inherits, and so does a `custom` without a usable
     * colour: it would render hovers with no colour at all.
     */
    public static function highlight(mixed $raw, mixed $color): ?string
    {
        if (!is_string($raw) || !in_array($raw, ThemeContext::HIGHLIGHTS, true)) {
            return null;
        }

        return 'custom' === $raw && null === self::color($color) ? null : $raw;
    }
}
