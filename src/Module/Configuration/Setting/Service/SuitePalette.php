<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Service;

use Aurora\Module\Configuration\Theme\Service\PrimaryColorPalette;
use JsonException;

use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function preg_match;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The greys of the back office and the client space, in light and dark.
 *
 * Each mode starts from a family of greys (neutral, bluish, warm...) whose
 * steps feed the `theme.css` tokens, then each token can be adjusted by hand.
 * The `gray` family with no adjustment reproduces `theme.css` to the pixel: a
 * mode left at the default emits no rule.
 *
 * The four state colours (success, warning, error, information) are adjusted
 * the same way; their pale background and their badges are derived from them.
 *
 * **Every colour is checked here.** They end up in a `<style>` tag: only a
 * six-digit hexadecimal gets through.
 */
final class SuitePalette
{
    public const string DEFAULT_FAMILY = 'gray';

    public const array MODES = ['light', 'dark'];

    /** The Tailwind steps of each family, from 50 to 950. */
    public const array FAMILIES = [
        'gray' => [
            '50' => '#f9fafb', '100' => '#f3f4f6', '200' => '#e5e7eb', '300' => '#d1d5db', '400' => '#9ca3af',
            '500' => '#6b7280', '600' => '#4b5563', '700' => '#374151', '800' => '#1f2937', '900' => '#111827', '950' => '#030712',
        ],
        'slate' => [
            '50' => '#f8fafc', '100' => '#f1f5f9', '200' => '#e2e8f0', '300' => '#cbd5e1', '400' => '#94a3b8',
            '500' => '#64748b', '600' => '#475569', '700' => '#334155', '800' => '#1e293b', '900' => '#0f172a', '950' => '#020617',
        ],
        'zinc' => [
            '50' => '#fafafa', '100' => '#f4f4f5', '200' => '#e4e4e7', '300' => '#d4d4d8', '400' => '#a1a1aa',
            '500' => '#71717a', '600' => '#52525b', '700' => '#3f3f46', '800' => '#27272a', '900' => '#18181b', '950' => '#09090b',
        ],
        'neutral' => [
            '50' => '#fafafa', '100' => '#f5f5f5', '200' => '#e5e5e5', '300' => '#d4d4d4', '400' => '#a3a3a3',
            '500' => '#737373', '600' => '#525252', '700' => '#404040', '800' => '#262626', '900' => '#171717', '950' => '#0a0a0a',
        ],
        'stone' => [
            '50' => '#fafaf9', '100' => '#f5f5f4', '200' => '#e7e5e4', '300' => '#d6d3d1', '400' => '#a8a29e',
            '500' => '#78716c', '600' => '#57534e', '700' => '#44403c', '800' => '#292524', '900' => '#1c1917', '950' => '#0c0a09',
        ],
    ];

    /**
     * Token => [CSS variable, light step, dark step]. The steps are the ones
     * `theme.css` hard-codes for the `gray` family.
     */
    public const array TOKENS = [
        'bg' => ['--th-bg', '50', '950'],
        'surface' => ['--th-surface', 'white', '900'],
        'surface_2' => ['--th-surface-2', '100', '800'],
        'surface_3' => ['--th-surface-3', '200', '700'],
        'line' => ['--color-border', '200', '700'],
        'line_strong' => ['--color-border-strong', '300', '600'],
        'primary' => ['--th-primary', '900', '100'],
        'secondary' => ['--th-secondary', '500', '400'],
        'muted' => ['--th-muted', '400', '500'],
        'subtle' => ['--th-subtle', '300', '600'],
    ];

    /**
     * State colour => [CSS variable, light default, dark default], the values
     * of `theme.css`. The pale background (`-soft`) and the badge pair
     * (`--th-badge-*-bg`/`-text`) are derived from the chosen colour.
     */
    public const array STATE_TOKENS = [
        'success' => ['--th-success', '#10b981', '#34d399'],
        'warning' => ['--th-warning', '#f59e0b', '#fbbf24'],
        'danger' => ['--th-danger', '#f43f5e', '#fb7185'],
        'info' => ['--th-info', '#38bdf8', '#7dd3fc'],
    ];

    /** Opacity of the pale background, the one in `theme.css`: 0.10 in light, 0.15 in dark. */
    private const array SOFT_ALPHA = ['light' => '1a', 'dark' => '26'];

    /** Badge steps [background, text], the ones `theme.css` takes from Tailwind. */
    private const array BADGE_STEPS = ['light' => [100, 700], 'dark' => [900, 300]];

    private const string HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * Reads the stored value; anything unknown or malformed falls back to the
     * default rather than failing, the page must render whatever happens.
     *
     * @return array<string, array{family: string, overrides: array<string, string>}>
     */
    public static function fromStored(?string $raw): array
    {
        $decoded = self::decode($raw);

        return self::normalize(is_array($decoded) ? $decoded : []);
    }

    /**
     * For saving: null when the value is not a JSON object.
     */
    public static function normalizeForStorage(?string $raw): ?string
    {
        $decoded = self::decode($raw);
        if (!is_array($decoded)) {
            return null;
        }

        return json_encode(self::normalize($decoded), JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, array{family: string, overrides: array<string, string>}>
     */
    public static function normalize(array $input): array
    {
        $palette = [];
        foreach (self::MODES as $mode) {
            $modeInput = is_array($input[$mode] ?? null) ? $input[$mode] : [];
            $family = $modeInput['family'] ?? null;
            $overrides = [];
            foreach (is_array($modeInput['overrides'] ?? null) ? $modeInput['overrides'] : [] as $token => $hex) {
                if ((array_key_exists($token, self::TOKENS) || array_key_exists($token, self::STATE_TOKENS)) && is_string($hex) && 1 === preg_match(self::HEX_PATTERN, $hex)) {
                    $overrides[$token] = mb_strtolower($hex);
                }
            }

            $palette[$mode] = [
                'family' => is_string($family) && array_key_exists($family, self::FAMILIES) ? $family : self::DEFAULT_FAMILY,
                'overrides' => $overrides,
            ];
        }

        return $palette;
    }

    /**
     * The colour of each token for a mode: the one adjusted by hand, otherwise
     * the family's step.
     *
     * @param array<string, array{family: string, overrides: array<string, string>}> $palette
     *
     * @return array<string, string>
     */
    public static function resolve(array $palette, string $mode): array
    {
        $index = 'light' === $mode ? 1 : 2;
        $scale = self::FAMILIES[$palette[$mode]['family']];
        $colors = [];
        foreach (self::TOKENS as $token => $definition) {
            $step = $definition[$index];
            $colors[$token] = $palette[$mode]['overrides'][$token] ?? ('white' === $step ? '#ffffff' : $scale[$step]);
        }

        return $colors;
    }

    /**
     * The rules to put in the page, empty when nothing changed.
     *
     * `:root:not(.dark)` and `:root.dark` weigh more than the `:root` and the
     * `.dark` of `theme.css`, so the order of the stylesheets does not matter;
     * and each applies only to its mode, so an adjusted light mode never
     * spills over onto a dark mode left at the default.
     *
     * @param array<string, array{family: string, overrides: array<string, string>}> $palette
     */
    public static function css(array $palette): string
    {
        $selectors = ['light' => ':root:not(.dark)', 'dark' => ':root.dark'];
        $colorScale = new PrimaryColorPalette();
        $css = '';
        foreach (self::MODES as $mode) {
            if (self::DEFAULT_FAMILY === $palette[$mode]['family'] && [] === $palette[$mode]['overrides']) {
                continue;
            }

            $declarations = '';
            foreach (self::resolve($palette, $mode) as $token => $hex) {
                $declarations .= sprintf('%s:%s;', self::TOKENS[$token][0], $hex);
            }

            foreach (self::STATE_TOKENS as $token => [$variable]) {
                $hex = $palette[$mode]['overrides'][$token] ?? null;
                if (null === $hex) {
                    continue;
                }

                [$badgeBackground, $badgeText] = self::BADGE_STEPS[$mode];
                $scale = $colorScale->generate($hex);
                $declarations .= sprintf(
                    '%1$s:%2$s;%1$s-soft:%2$s%3$s;--th-badge-%4$s-bg:%5$s;--th-badge-%4$s-text:%6$s;',
                    $variable,
                    $hex,
                    self::SOFT_ALPHA[$mode],
                    $token,
                    $scale[$badgeBackground],
                    $scale[$badgeText],
                );
            }

            $css .= sprintf('%s{%s}', $selectors[$mode], $declarations);
        }

        return $css;
    }

    private static function decode(?string $raw): mixed
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
