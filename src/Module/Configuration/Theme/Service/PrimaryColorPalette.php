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
        [$red, $green, $blue] = $this->hexToRgb($hex);
        [, $chroma, $hue] = $this->rgbToOklch($red, $green, $blue);

        $palette = [];
        foreach (self::LIGHTNESS_STOPS as $stop => $lightness) {
            $palette[$stop] = sprintf('oklch(%.3f %.3f %.3f)', $lightness, $chroma, $hue);
        }

        return $palette;
    }

    /**
     * A hex colour in OKLCH: lightness 0..1, chroma, hue in degrees.
     *
     * @return array{float, float, float}
     */
    public function toOklch(string $hex): array
    {
        return $this->rgbToOklch(...$this->hexToRgb($hex));
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
    private function rgbToOklch(int $red, int $green, int $blue): array
    {
        [$linearRed, $linearGreen, $linearBlue] = [
            $this->srgbToLinear($red / 255),
            $this->srgbToLinear($green / 255),
            $this->srgbToLinear($blue / 255),
        ];

        // Linear sRGB → LMS (long, medium, short cone responses)
        $long = 0.4122214708 * $linearRed + 0.5363325363 * $linearGreen + 0.0514459929 * $linearBlue;
        $medium = 0.2119034982 * $linearRed + 0.6806995451 * $linearGreen + 0.1073969566 * $linearBlue;
        $short = 0.0883024619 * $linearRed + 0.2817188376 * $linearGreen + 0.6299787005 * $linearBlue;

        $longRoot = $this->cbrt($long);
        $mediumRoot = $this->cbrt($medium);
        $shortRoot = $this->cbrt($short);

        $okL = 0.2104542553 * $longRoot + 0.7936177850 * $mediumRoot - 0.0040720468 * $shortRoot;
        $okA = 1.9779984951 * $longRoot - 2.4285922050 * $mediumRoot + 0.4505937099 * $shortRoot;
        $okB = 0.0259040371 * $longRoot + 0.7827717662 * $mediumRoot - 0.8086757660 * $shortRoot;

        $chroma = sqrt($okA * $okA + $okB * $okB);
        $hue = atan2($okB, $okA) * 180 / M_PI;
        if ($hue < 0) {
            $hue += 360;
        }

        return [$okL, $chroma, $hue];
    }

    private function srgbToLinear(float $channel): float
    {
        return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    /** Real-valued cube root (PHP pow() goes NaN on negatives with fractional exponent). */
    private function cbrt(float $x): float
    {
        return $x < 0 ? -(-$x) ** (1 / 3) : $x ** (1 / 3);
    }
}
