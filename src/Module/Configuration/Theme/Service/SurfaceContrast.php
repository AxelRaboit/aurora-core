<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Service;

/**
 * Décide, pour une couleur de fond donnée, quel jeu de jetons de texte et de
 * bordure la rend lisible.
 *
 * Le frontend n'écrit jamais une couleur de texte en dur : il utilise quatre
 * jetons hiérarchisés (`--th-primary` pour le texte fort, `--th-secondary` pour
 * les libellés de menu, `--th-muted` et `--th-subtle` pour les mentions
 * discrètes) et deux jetons de bordure. Basculer la seule couleur principale sur
 * un fond sombre laisserait donc les gris moyens et les traits de séparation
 * invisibles. La décision porte sur le jeu entier, pas sur une couleur.
 *
 * Les deux jeux sont ceux que `theme.css` définit déjà pour `:root` et `.dark`.
 * Ils sont repris tels quels plutôt que réinventés : le backend en mode sombre
 * les éprouve tous les jours.
 *
 * Le choix se fait au rapport de contraste WCAG, pas à un seuil de luminance à
 * 50 %. Un seuil se trompe sur les couleurs très saturées : un rouge vif et un
 * bleu vif partagent une luminance moyenne mais n'appellent pas le même texte.
 */
final class SurfaceContrast
{
    /** Texte fort du jeu clair et du jeu sombre, tels quels dans theme.css. */
    private const string LIGHT_PRIMARY = 'rgb(17 24 39)';

    private const string DARK_PRIMARY = 'rgb(243 244 246)';

    /**
     * Seuil AAA de WCAG 2.1 pour du texte de taille normale.
     *
     * C'est bien AAA et non AA, parce qu'AA ne peut pas échouer ici. Le service
     * retient toujours le meilleur du noir et du blanc, et ce meilleur ne
     * descend jamais sous **4,608:1** - le minimum est atteint sur le gris
     * `#757575`, là où noir et blanc se valent. AA demandant 4,5, il est tenu
     * par construction : un avertissement AA serait une interface morte.
     *
     * AAA se franchit en revanche sur toute la zone des tons moyens, ce qui en
     * fait le seul seuil informatif à signaler dans l'écran de thème.
     */
    public const float AAA_NORMAL_TEXT = 7.0;

    /**
     * Plancher garanti par la stratégie « meilleur des deux ». Exposé pour que
     * le jour où quelqu'un doute, la valeur soit dans le code et pas dans un
     * souvenir.
     */
    public const float GUARANTEED_FLOOR = 4.608;

    /**
     * Le fond appelle-t-il un texte clair ?
     *
     * Vrai quand du blanc contraste mieux que du noir, ce qui revient à demander
     * « ce fond est-il sombre ? » sans avoir à fixer arbitrairement la frontière.
     */
    public function needsLightText(string $hex): bool
    {
        return $this->ratioAgainstWhite($hex) > $this->ratioAgainstBlack($hex);
    }

    /**
     * Rapport de contraste WCAG entre deux couleurs, de 1 (identiques) à 21
     * (noir sur blanc).
     */
    public function ratio(string $hexA, string $hexB): float
    {
        $a = $this->relativeLuminance($hexA);
        $b = $this->relativeLuminance($hexB);

        [$lighter, $darker] = $a > $b ? [$a, $b] : [$b, $a];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Le contraste obtenu tient-il le seuil AAA pour du texte courant ?
     *
     * Sert l'avertissement de l'écran de thème : la couleur reste acceptée, mais
     * signalée comme confortable ou seulement correcte. Voir AAA_NORMAL_TEXT
     * pour la raison du choix de ce seuil plutôt que d'AA.
     */
    public function meetsAaa(string $backgroundHex): bool
    {
        return $this->bestRatio($backgroundHex) >= self::AAA_NORMAL_TEXT;
    }

    /** Le meilleur rapport atteignable sur ce fond, blanc ou noir confondus. */
    public function bestRatio(string $backgroundHex): float
    {
        return max($this->ratioAgainstWhite($backgroundHex), $this->ratioAgainstBlack($backgroundHex));
    }

    /**
     * Le jeu complet de jetons pour une surface de cette couleur.
     *
     * @return array<string, string> nom de variable CSS => valeur
     */
    public function tokensFor(string $backgroundHex): array
    {
        return $this->needsLightText($backgroundHex)
            ? [
                '--th-primary' => self::DARK_PRIMARY,
                '--th-secondary' => 'rgb(156 163 175)',
                '--th-muted' => 'rgb(107 114 128)',
                '--th-subtle' => 'rgb(75 85 99)',
                '--th-surface' => 'rgb(17 24 39)',
                '--th-surface-2' => 'rgb(31 41 55)',
                '--th-surface-3' => 'rgb(55 65 81)',
                '--color-border' => 'rgb(55 65 81)',
                '--color-border-strong' => 'rgb(75 85 99)',
            ]
            : [
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

    /** Luminance relative WCAG, 0 pour le noir, 1 pour le blanc. */
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
     * Accepte `#abc`, `#aabbcc` et les mêmes sans dièse. Une saisie illisible
     * retombe sur le blanc, ce qui donne le jeu clair : le défaut historique du
     * frontend, donc le moins surprenant.
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
