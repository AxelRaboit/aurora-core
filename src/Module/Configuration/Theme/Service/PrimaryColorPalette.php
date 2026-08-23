<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Service;

/**
 * Generates an 11-stop Tailwind-style colour scale (50 → 950) from a single hex seed.
 *
 * Strategy: convert the seed RGB → OKLCH (perceptually-uniform colour space used by
 * Tailwind 4), keep its chroma & hue constant, then ramp the lightness across the 11
 * canonical Tailwind lightness values. The output mirrors Tailwind's own scale shape
 * so swapping the seed changes hue without breaking the visual hierarchy (light
 * shades stay light, dark shades stay dark).
 *
 * Used by {@see ThemeContext::primaryColorCss()} to define the --color-accent-*
 * CSS variables at runtime.
 */
final class PrimaryColorPalette
{
    /** Tailwind-aligned lightness targets, in OKL space (0..1). */
    private const array LIGHTNESS_STOPS = [
        '50' => 0.97,
        '100' => 0.93,
        '200' => 0.87,
        '300' => 0.78,
        '400' => 0.68,
        '500' => 0.585,
        '600' => 0.49,
        '700' => 0.40,
        '800' => 0.32,
        '900' => 0.25,
        '950' => 0.16,
    ];

    /**
     * Returns the palette as `[stop => oklch(...)]`. Note: PHP coerces numeric-string array
     * keys to int, so '500' becomes 500 - this is intentional and harmless since callers
     * iterate the array and use the stop only for CSS interpolation.
     *
     * @return array<int, string> stop number → CSS oklch() value
     */
    public function generate(string $hex): array
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        [, $chroma, $hue] = $this->rgbToOklch($r, $g, $b);

        $palette = [];
        foreach (self::LIGHTNESS_STOPS as $stop => $lightness) {
            $palette[$stop] = sprintf('oklch(%.3f %.3f %.3f)', $lightness, $chroma, $hue);
        }

        return $palette;
    }

    /** @return array{int, int, int} */
    private function hexToRgb(string $hex): array
    {
        $hex = mb_ltrim($hex, '#');
        if (3 === mb_strlen($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (6 !== mb_strlen($hex) || !ctype_xdigit($hex)) {
            // Fallback to the default accent hue when the input is malformed.
            return [99, 102, 241];
        }

        return [hexdec(mb_substr($hex, 0, 2)), hexdec(mb_substr($hex, 2, 2)), hexdec(mb_substr($hex, 4, 2))];
    }

    /**
     * sRGB → OKLCH. Reference: https://bottosson.github.io/posts/oklab/.
     *
     * @return array{float, float, float} L, C, H (H in degrees, 0..360)
     */
    private function rgbToOklch(int $r, int $g, int $b): array
    {
        [$lr, $lg, $lb] = [
            $this->srgbToLinear($r / 255),
            $this->srgbToLinear($g / 255),
            $this->srgbToLinear($b / 255),
        ];

        // Linear sRGB → LMS
        $l = 0.4122214708 * $lr + 0.5363325363 * $lg + 0.0514459929 * $lb;
        $m = 0.2119034982 * $lr + 0.6806995451 * $lg + 0.1073969566 * $lb;
        $s = 0.0883024619 * $lr + 0.2817188376 * $lg + 0.6299787005 * $lb;

        $l_ = $this->cbrt($l);
        $m_ = $this->cbrt($m);
        $s_ = $this->cbrt($s);

        $okL = 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_;
        $okA = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
        $okB = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

        $chroma = sqrt($okA * $okA + $okB * $okB);
        $hue = atan2($okB, $okA) * 180 / M_PI;
        if ($hue < 0) {
            $hue += 360;
        }

        return [$okL, $chroma, $hue];
    }

    private function srgbToLinear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    /** Real-valued cube root (PHP pow() goes NaN on negatives with fractional exponent). */
    private function cbrt(float $x): float
    {
        return $x < 0 ? -(-$x) ** (1 / 3) : $x ** (1 / 3);
    }
}
