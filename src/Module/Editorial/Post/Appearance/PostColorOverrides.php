<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Appearance;

use function in_array;
use function is_array;
use function is_string;
use function mb_trim;
use function preg_match;

/**
 * The theme colours a post repaints for itself alone, beyond the background,
 * the topbar and the footer (which have had their own columns for a long time).
 *
 * **The keys are the theme's**, not separate names: the theme resolves them
 * (`ThemeStyleRenderer::frontendSurfacesCss`), surface by surface against its
 * own configuration, and a missing key lets the theme's own value through.
 * So a post has nothing to translate, and the theme screen and the Appearance
 * tab talk about the same settings.
 *
 * **Every colour is checked here.** They end up in a public `<style>`: a value
 * that is not hexadecimal is dropped, and so is an unknown key. Dropping means
 * "the theme's one", never "none".
 */
final class PostColorOverrides
{
    /** Text, lines, cards, headings and highlighted figures. */
    public const array KEYS = [
        'text_color',
        'line_color',
        'card_color',
        'card_line_color',
        'heading_color',
        'figure_color',
    ];

    /** @return array<string, string> */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $colors = [];
        foreach ($raw as $key => $value) {
            if (!in_array($key, self::KEYS, true)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $color = mb_trim($value);
            if (1 === preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $colors[$key] = $color;
            }
        }

        return $colors;
    }
}
