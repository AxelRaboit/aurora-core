<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\Service;

use Aurora\Module\Configuration\Theme\Service\ThemeContext;

use function in_array;
use function is_array;
use function is_string;
use function mb_trim;
use function preg_match;

/**
 * Ce qu'un livrable repeint pour lui seul, et la seule porte d'entrée vers le
 * `<style>` de sa page.
 *
 * **Toute couleur est vérifiée ici, à l'écriture.** Elles finissent dans une
 * balise `<style>` publique : une valeur non contrôlée y serait du CSS
 * arbitraire. Une couleur qui n'est pas un hexadécimal est donc rendue nulle,
 * et nulle veut dire « celle du thème », jamais « aucune ».
 *
 * Les clés sont celles des publications pour les surfaces (fond, en-tête,
 * pied), parce que c'est le thème qui les résout et qu'il les connaît déjà ;
 * le livrable y ajoute la couleur des titres et celle des chiffres clés, qu'un
 * document aux couleurs du client a toutes les raisons de changer.
 */
final class DeliverableAppearance
{
    /** Les couleurs qu'un livrable peut choisir, et ce qu'elles repeignent. */
    public const array COLORS = [
        'backgroundColor',
        'headerColor',
        'footerColor',
        'accentColor',
        'headingColor',
        'figureColor',
    ];

    /**
     * @return array{backgroundColor: ?string, headerColor: ?string, footerColor: ?string, accentColor: ?string, headingColor: ?string, figureColor: ?string, highlight: ?string, highlightColor: ?string, titleVisible: bool}
     */
    public static function normalize(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];

        $appearance = [];
        foreach (self::COLORS as $key) {
            $appearance[$key] = self::color($data[$key] ?? null);
        }

        $highlightColor = self::color($data['highlightColor'] ?? null);
        $highlight = is_string($data['highlight'] ?? null) && in_array($data['highlight'], ThemeContext::HIGHLIGHTS, true)
            ? $data['highlight']
            : null;

        // « Personnalisé » sans couleur ne veut rien dire : retour au thème.
        if ('custom' === $highlight && null === $highlightColor) {
            $highlight = null;
        }

        return [
            ...$appearance,
            'highlight' => $highlight,
            'highlightColor' => $highlightColor,
            // Le titre et le résumé en tête de page. Vrai par défaut : un
            // livrable qui ouvre sur un bloc d'entête l'éteint lui-même.
            'titleVisible' => false !== ($data['titleVisible'] ?? true),
        ];
    }

    private static function color(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $value = mb_trim($raw);

        return 1 === preg_match(ThemeContext::HEX_COLOR, $value) ? $value : null;
    }
}
