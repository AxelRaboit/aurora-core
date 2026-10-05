<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Module\Configuration\Theme\Service\AppearanceValues;

use function in_array;
use function is_array;

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
     * Comment les grands titres sont dessinés : ceux du thème, ou en capitales
     * grasses, le titre de page d'un rapport (« ANALYSE DE L'ENGAGEMENT »).
     */
    public const array HEADING_STYLES = ['theme', 'display'];

    /**
     * Comment le document se lit : une page qu'on fait défiler, ou une
     * présentation qu'on fait passer section par section.
     */
    public const array DISPLAYS = ['page', 'slides'];

    /**
     * @return array{backgroundColor: ?string, headerColor: ?string, footerColor: ?string, accentColor: ?string, headingColor: ?string, figureColor: ?string, highlight: ?string, highlightColor: ?string, titleVisible: bool, headingStyle: string, display: string, readerPdf: bool}
     */
    public static function normalize(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];

        $appearance = [];
        foreach (self::COLORS as $key) {
            $appearance[$key] = AppearanceValues::color($data[$key] ?? null);
        }

        // « Personnalisé » sans couleur ne veut rien dire : retour au thème.
        $highlight = AppearanceValues::highlight($data['highlight'] ?? null, $data['highlightColor'] ?? null);
        $highlightColor = AppearanceValues::color($data['highlightColor'] ?? null);

        return [
            ...$appearance,
            'highlight' => $highlight,
            'highlightColor' => $highlightColor,
            // Le titre et le résumé en tête de page. Vrai par défaut : un
            // livrable qui ouvre sur un bloc d'entête l'éteint lui-même.
            'titleVisible' => false !== ($data['titleVisible'] ?? true),
            'headingStyle' => in_array($data['headingStyle'] ?? null, self::HEADING_STYLES, true) ? $data['headingStyle'] : self::HEADING_STYLES[0],
            'display' => in_array($data['display'] ?? null, self::DISPLAYS, true) ? $data['display'] : self::DISPLAYS[0],
            // Le PDF du lecteur : éteint tant que l'auteur ne l'a pas permis.
            // Seul un `true` franc l'allume, jamais une chaîne ou un 1.
            'readerPdf' => true === ($data['readerPdf'] ?? false),
        ];
    }
}
