<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Service;

/**
 * Decides, for a given background color, which set of text and border tokens
 * makes it readable.
 *
 * The frontend never hardcodes a text color: it uses four ranked tokens
 * (`--th-primary` for strong text, `--th-secondary` for menu labels,
 * `--th-muted` and `--th-subtle` for discreet mentions) and two border
 * tokens. Switching only the main color on a dark background would therefore
 * leave the mid greys and the separator lines invisible. The decision covers
 * the whole set, not one color.
 *
 * Both sets are the ones `theme.css` already defines for `:root` and `.dark`.
 * They are taken as they are rather than reinvented: the suite in dark mode
 * tests them every day.
 *
 * The choice is made on the WCAG contrast ratio, not on a luminance threshold
 * at 50%. A threshold gets very saturated colors wrong: a bright red and a
 * bright blue share a mid luminance but do not call for the same text.
 */
final readonly class SurfaceContrast
{
    /**
     * OKLCH lightness added to a dark background for surface, surface-2,
     * surface-3 (and the border) and the strong border. Measured on the slate
     * set against `#030712`, see darkSurfacesFor().
     */
    private const array SURFACE_STEPS = [0.08, 0.148, 0.243, 0.316];

    /**
     * OKLCH lightness between the strong text and secondary, muted and subtle,
     * toward the background. Measured on the default sets: on dark,
     * rgb(243 244 246) against rgb(156 163 175), rgb(107 114 128) and
     * rgb(75 85 99); on light, rgb(17 24 39) against their light twins.
     */
    private const array DARK_TEXT_STEPS = [0.253, 0.416, 0.521];

    private const array LIGHT_TEXT_STEPS = [0.341, 0.504, 0.660];

    private PrimaryColorPalette $palette;

    public function __construct(?PrimaryColorPalette $palette = null)
    {
        $this->palette = $palette ?? new PrimaryColorPalette();
    }

    /** Strong text of the light set and the dark set, as they are in theme.css. */
    private const string LIGHT_PRIMARY = 'rgb(17 24 39)';

    private const string DARK_PRIMARY = 'rgb(243 244 246)';

    /**
     * WCAG 2.1 AAA threshold for normal-size text.
     *
     * It really is AAA and not AA, because AA cannot fail here. The service
     * always keeps the better of black and white, and that better one never
     * goes below **4.608:1** - the minimum is reached on the grey `#757575`,
     * where black and white are equal. AA asking for 4.5, it holds by
     * construction: an AA warning would be dead interface.
     *
     * AAA, on the other hand, is crossed across the whole range of mid tones,
     * which makes it the only informative threshold to flag in the theme
     * screen.
     */
    public const float AAA_NORMAL_TEXT = 7.0;

    /**
     * Floor guaranteed by the "better of the two" strategy. Exposed so that
     * the day someone doubts it, the value is in the code and not in a
     * memory.
     */
    public const float GUARANTEED_FLOOR = 4.608;

    /**
     * Does the background call for light text?
     *
     * True when white contrasts better than black, which amounts to asking
     * "is this background dark?" without having to set the border arbitrarily.
     */
    public function needsLightText(string $hex): bool
    {
        return $this->ratioAgainstWhite($hex) > $this->ratioAgainstBlack($hex);
    }

    /**
     * WCAG contrast ratio between two colors, from 1 (identical) to 21
     * (black on white).
     */
    public function ratio(string $hexA, string $hexB): float
    {
        $a = $this->relativeLuminance($hexA);
        $b = $this->relativeLuminance($hexB);

        [$lighter, $darker] = $a > $b ? [$a, $b] : [$b, $a];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Does the resulting contrast meet the AAA threshold for body text?
     *
     * Serves the theme screen warning: the color is still accepted, but
     * flagged as comfortable or only adequate. See AAA_NORMAL_TEXT for why
     * this threshold was chosen rather than AA.
     */
    public function meetsAaa(string $backgroundHex): bool
    {
        return $this->bestRatio($backgroundHex) >= self::AAA_NORMAL_TEXT;
    }

    /** The best ratio reachable on this background, white or black alike. */
    public function bestRatio(string $backgroundHex): float
    {
        return max($this->ratioAgainstWhite($backgroundHex), $this->ratioAgainstBlack($backgroundHex));
    }

    /**
     * The full set of tokens for a surface of this color.
     *
     * @return array<string, string> CSS variable name => value
     */
    public function tokensFor(string $backgroundHex): array
    {
        if (!$this->needsLightText($backgroundHex)) {
            return $this->lightTokens();
        }

        return [...$this->darkTokens(), ...$this->darkSurfacesFor($backgroundHex)];
    }

    /**
     * Cards, panels and borders in the background's own hue, a step lighter
     * each.
     *
     * The dark set used to carry them as fixed slate greys, which only ever
     * belonged to one background: on a violet page, every card stayed a
     * blue-grey box. The steps are the ones that slate already made against
     * `#030712` - measured in OKLCH, `rgb(17 24 39)` is that background 0.08
     * lighter, `rgb(31 41 55)` 0.15, `rgb(55 65 81)` 0.24, at the same hue and
     * nearly the same chroma - so a navy page keeps its look to within a
     * shade, and any other page gets the same hierarchy in its own colour.
     *
     * Only for a dark background: on a light one, white cards over a tinted
     * page already read as cards.
     *
     * @return array<string, string>
     */
    private function darkSurfacesFor(string $backgroundHex): array
    {
        [$lightness, $chroma, $hue] = $this->palette->toOklch($backgroundHex);
        $step = fn (float $lift): string => $this->oklch($lightness + $lift, $chroma, $hue);

        return [
            '--th-surface' => $step(self::SURFACE_STEPS[0]),
            '--th-surface-2' => $step(self::SURFACE_STEPS[1]),
            '--th-surface-3' => $step(self::SURFACE_STEPS[2]),
            '--color-border' => $step(self::SURFACE_STEPS[2]),
            '--color-border-strong' => $step(self::SURFACE_STEPS[3]),
        ];
    }

    /**
     * The text and line tokens a theme's own ink colours ask for, on top of
     * the ones tokensFor() chose for this background.
     *
     * The text colour becomes `--th-primary`, and the three quieter greys are
     * that same colour stepped toward the background - so a warm off-white
     * gives warm labels too, rather than cream titles over blue-grey captions.
     * The steps are the default set's, measured in OKLCH. It is skipped on a
     * surface it would not be readable on (under 4.5:1, WCAG AA): a cream
     * meant for dark pages must not turn a light footer's text to cream.
     *
     * The line colour becomes the border, and the strong border a step
     * further from the background. The card outline, when set, replaces the
     * border on cards only.
     *
     * @return array<string, string>
     */
    public function inkTokensFor(string $backgroundHex, ?string $textHex, ?string $lineHex, ?string $cardLineHex = null): array
    {
        $tokens = [];
        $towardBackground = $this->needsLightText($backgroundHex) ? -1.0 : 1.0;

        if (null !== $textHex && $this->ratio($textHex, $backgroundHex) >= 4.5) {
            [$lightness, $chroma, $hue] = $this->palette->toOklch($textHex);
            $steps = -1.0 === $towardBackground ? self::DARK_TEXT_STEPS : self::LIGHT_TEXT_STEPS;
            $tokens['--th-primary'] = $textHex;
            foreach (['--th-secondary', '--th-muted', '--th-subtle'] as $i => $token) {
                $tokens[$token] = $this->oklch($lightness + $towardBackground * $steps[$i], $chroma, $hue);
            }
        }

        if (null !== $lineHex) {
            [$lightness, $chroma, $hue] = $this->palette->toOklch($lineHex);
            $tokens['--color-border'] = $lineHex;
            $tokens['--color-border-strong'] = $this->oklch($lightness - $towardBackground * 0.073, $chroma, $hue);
        }

        // Cards may want an outline of their own: a cream rule under the top
        // bar reads as a line, the same cream around every card reads as a
        // frame. Unset, `--color-card-line` falls back to the border.
        if (null !== $cardLineHex) {
            $tokens['--th-card-line'] = $cardLineHex;
        }

        return $tokens;
    }

    /**
     * The card tokens a theme's own card colour asks for, on top of the ones
     * derived from the background.
     *
     * The colour becomes `--th-surface`, and the two raised levels stay the
     * steps apart they are in the derived set, lighter on a dark page and
     * darker on a light one, so a panel inside a card still reads as one.
     *
     * @return array<string, string>
     */
    public function cardTokensFor(string $backgroundHex, ?string $cardHex): array
    {
        if (null === $cardHex) {
            return [];
        }

        [$lightness, $chroma, $hue] = $this->palette->toOklch($cardHex);
        $direction = $this->needsLightText($backgroundHex) ? 1.0 : -1.0;

        return [
            '--th-surface' => $cardHex,
            '--th-surface-2' => $this->oklch($lightness + $direction * (self::SURFACE_STEPS[1] - self::SURFACE_STEPS[0]), $chroma, $hue),
            '--th-surface-3' => $this->oklch($lightness + $direction * (self::SURFACE_STEPS[2] - self::SURFACE_STEPS[0]), $chroma, $hue),
        ];
    }

    /**
     * The same two token sets, named rather than derived from a colour - for
     * a zone whose author picked "light" or "dark" outright instead of a
     * background to contrast against.
     *
     * @return array<string, string> nom de variable CSS => valeur
     */
    public function tokensForScheme(string $scheme): array
    {
        return 'dark' === $scheme ? $this->darkTokens() : $this->lightTokens();
    }

    private function oklch(float $lightness, float $chroma, float $hue): string
    {
        return sprintf('oklch(%.3f %.3f %.3f)', max(0.0, min(1.0, $lightness)), $chroma, $hue);
    }

    /** @return array<string, string> */
    private function darkTokens(): array
    {
        return [
            '--th-primary' => self::DARK_PRIMARY,
            '--th-secondary' => 'rgb(156 163 175)',
            '--th-muted' => 'rgb(107 114 128)',
            '--th-subtle' => 'rgb(75 85 99)',
            '--th-surface' => 'rgb(17 24 39)',
            '--th-surface-2' => 'rgb(31 41 55)',
            '--th-surface-3' => 'rgb(55 65 81)',
            '--color-border' => 'rgb(55 65 81)',
            '--color-border-strong' => 'rgb(75 85 99)',
        ];
    }

    /** @return array<string, string> */
    private function lightTokens(): array
    {
        return [
            '--th-primary' => self::LIGHT_PRIMARY,
            '--th-secondary' => 'rgb(107 114 128)',
            '--th-muted' => 'rgb(156 163 175)',
            '--th-subtle' => 'rgb(209 213 219)',
            '--th-surface' => 'rgb(255 255 255)',
            '--th-surface-2' => 'rgb(243 244 246)',
            '--th-surface-3' => 'rgb(229 231 235)',
            '--color-border' => 'rgb(229 231 235)',
            '--color-border-strong' => 'rgb(209 213 219)',
        ];
    }

    private function ratioAgainstWhite(string $hex): float
    {
        return $this->ratio($hex, '#ffffff');
    }

    private function ratioAgainstBlack(string $hex): float
    {
        return $this->ratio($hex, '#000000');
    }

    /** WCAG relative luminance, 0 for black, 1 for white. */
    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = $this->hexToRgb($hex);

        return 0.2126 * $this->toLinear($r / 255)
             + 0.7152 * $this->toLinear($g / 255)
             + 0.0722 * $this->toLinear($b / 255);
    }

    private function toLinear(float $channel): float
    {
        return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    /**
     * Accepts `#abc`, `#aabbcc` and the same without the hash. An unreadable
     * input falls back to white, which gives the light set: the frontend's
     * historical default, so the least surprising one.
     *
     * @return array{int, int, int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = mb_ltrim(mb_trim($hex), '#');

        if (3 === mb_strlen($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (6 !== mb_strlen($hex) || !ctype_xdigit($hex)) {
            return [255, 255, 255];
        }

        return [
            (int) hexdec(mb_substr($hex, 0, 2)),
            (int) hexdec(mb_substr($hex, 2, 2)),
            (int) hexdec(mb_substr($hex, 4, 2)),
        ];
    }
}
