<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Appearance;

use function in_array;
use function is_array;
use function is_string;
use function mb_trim;
use function preg_match;

/**
 * Les couleurs du thème qu'une publication repeint pour elle seule, au-delà
 * du fond, de la topbar et du pied (qui ont leurs colonnes depuis longtemps).
 *
 * **Les clés sont celles du thème**, pas des noms à part : c'est le thème qui
 * les résout (`ThemeStyleRenderer::frontendSurfacesCss`), surface par surface
 * contre sa propre configuration, et une clé absente laisse passer la sienne.
 * Une publication n'a donc rien à traduire, et l'écran de thème et l'onglet
 * Apparence parlent des mêmes réglages.
 *
 * **Toute couleur est vérifiée ici.** Elles finissent dans un `<style>`
 * public : une valeur qui n'est pas un hexadécimal est écartée, et une clé
 * inconnue aussi. Écarter veut dire « celle du thème », jamais « aucune ».
 */
final class PostColorOverrides
{
    /** Texte, traits, cartes, titres et chiffres mis en avant. */
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
