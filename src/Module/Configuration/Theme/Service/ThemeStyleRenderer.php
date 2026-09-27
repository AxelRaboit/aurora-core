<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Service;

use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;

/**
 * Everything a theme writes into a `<style>` tag, and nothing else.
 *
 * Split off {@see ThemeContext}, which reads the theme; this writes CSS from
 * it. The split has a purpose beyond size: every value here ends up in a
 * public `<style>`, served to every visitor, and a theme's configuration is not
 * validated on the way in. This class is the one place to audit against
 * injection - each colour through `ThemeContext::HEX_COLOR`, each custom
 * property through its own filter.
 *
 * Exposed to Twig as the `themeStyles` global.
 */
final readonly class ThemeStyleRenderer
{
    public function __construct(
        private ThemeContext $themeContext,
        private PrimaryColorPalette $primaryColorPalette,
        private SurfaceContrast $surfaceContrast,
    ) {}

    /**
     * Clés de `Theme::config` portant les couleurs des trois surfaces publiques,
     * associées au sélecteur qu'elles habillent.
     */
    private const array SURFACES = [
        'background_color' => 'html[data-theme]',
        'header_color' => 'html[data-theme] .aurora-surface-header',
        'footer_color' => 'html[data-theme] .aurora-surface-footer',
    ];

    /**
     * La règle qui compose l'application dans la police du thème, posée dans le
     * `<head>` par `primary_color_style.html.twig`.
     *
     * Elle redéfinit `--th-font-sans`, dont `--font-sans` n'est qu'un renvoi :
     * `body` s'en sert directement et l'utilitaire `font-sans` en recopie le
     * `var(...)`, donc toute la page suit. Un thème resté sur Poppins n'émet
     * rien : le défaut vit déjà dans `theme.css`, et une règle qui répète un
     * défaut est une seconde copie à tenir à jour.
     */
    public function fontFamilyCss(): string
    {
        $font = $this->themeContext->font();

        if (ThemeFontEnum::default() === $font) {
            return '';
        }

        return ':root{--th-font-sans: '.$font->stack().';}';
    }

    /**
     * Le choix d'une publication, borné à son contenu comme son accent.
     *
     * Posé sur le conteneur **et** chacun de ses descendants, sous
     * `html[data-theme]` : le mode neutre du thème se pose élément par élément,
     * et seule une règle plus spécifique au même niveau le remplace. `initial`
     * efface la valeur du thème, et le repli sur l'accent reprend la main.
     */
    public function postHighlightCss(string $selector, ?string $highlight, ?string $color): string
    {
        $value = match ($highlight) {
            'accent' => 'initial',
            'neutral' => 'var(--th-primary)',
            'custom' => null !== $color && 1 === preg_match(ThemeContext::HEX_COLOR, $color) ? $color : null,
            default => null,
        };

        if (null === $value) {
            return '';
        }

        $scope = 'html[data-theme] '.$selector;

        return $scope.','.$scope.' *{--th-highlight: '.$value.';}';
    }

    public function highlightCss(): string
    {
        return $this->customColorCss($this->themeContext->highlight(), $this->themeContext->highlightColor(), '--th-highlight');
    }

    public function menuActiveCss(): string
    {
        return $this->customColorCss($this->themeContext->menuActive(), $this->themeContext->menuActiveColor(), '--th-menu-active');
    }

    /**
     * The theme-wide rule of a custom colour mode, or nothing. Neutral needs
     * no rule here: it is an attribute on `<html>` that theme.css keys on.
     */
    private function customColorCss(string $mode, ?string $color, string $property): string
    {
        return 'custom' === $mode && null !== $color ? 'html[data-theme]{'.$property.': '.$color.';}' : '';
    }

    /**
     * Generates the CSS that overrides the --th-accent-* scale from the active theme's
     * primary colour. Output goes inside a <style> in the layout head. Tailwind utilities
     * like bg-accent-600 emit `var(--color-accent-600)` which itself forwards to
     * `var(--th-accent-600)` - overriding --th-accent-* at runtime cascades to every
     * accent-coloured element in the app.
     */
    public function primaryColorCss(): string
    {
        return ':root{'.implode('', $this->accentScale($this->themeContext->primaryColor())).'}';
    }

    /**
     * A publication's own accent, under a selector scoped to its own content
     * rather than `:root` - so choosing ocre for the photography page never
     * touches the topbar or the footer, which stay the theme's.
     *
     * Empty when the post sets nothing: the theme's own `:root` rule, always
     * present, is then the whole answer, exactly as before this existed.
     */
    public function postAccentCss(string $selector, ?string $accentColor): string
    {
        if (null === $accentColor || '' === mb_trim($accentColor)) {
            return '';
        }

        $declarations = $this->accentScale($accentColor);

        // --th-accent est résolu une fois sur :root et hérité tel quel : sans
        // le reposer ici, `text-accent` et `bg-accent` gardaient la couleur du
        // thème au milieu d'une page qui en avait choisi une autre.
        $declarations[] = '--th-accent: var(--th-accent-500);--th-accent-hover: var(--th-accent-600);';

        return $selector.'{'.implode('', $declarations).'}'
            .'.dark '.$selector.'{--th-accent: var(--th-accent-400);--th-accent-hover: var(--th-accent-500);}';
    }

    public function cssVariableOverrides(): string
    {
        $config = $this->themeContext->activeTheme()?->getConfig() ?? [];
        if ([] === $config) {
            return '';
        }

        // Filtré à la lecture, faute de l'être à l'écriture : un nom de
        // propriété personnalisée, et une valeur qui ne peut ni fermer la
        // déclaration, ni la règle, ni la balise `<style>` qui la porte.
        $parts = [];
        foreach ($config as $key => $value) {
            if (is_string($value)
                && 1 === preg_match('/^--[A-Za-z0-9_-]+$/', (string) $key)
                && 0 === preg_match('/[;{}<>\\\\]|\/\*/', $value)) {
                $parts[] = $key.': '.$value.';';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Le CSS qui colore le frontend public à partir des couleurs choisies dans
     * l'écran de thème.
     *
     * Une surface non configurée n'émet aucune règle : l'apparence historique
     * (fond clair, texte sombre, topbar et pied transparents) est donc le
     * comportement par défaut, sans valeur à maintenir quelque part.
     *
     * Chaque règle pose le fond **et** le jeu de jetons contrasté qui va avec,
     * au même endroit. Les propriétés personnalisées étant héritées, tout ce que
     * la surface contient suit : libellés, mentions discrètes, bordures, et les
     * panneaux de menu déroulant, peints en `bg-bg`, qui se retrouvent ainsi sur
     * le fond de leur topbar plutôt que sur celui de la page.
     *
     * `$overrides` porte les couleurs de la page en cours de rendu, quand elle
     * en a - une publication peut habiller ses trois surfaces pour elle seule.
     * La substitution se fait surface par surface, et pas en bloc : une
     * publication qui ne choisit que sa topbar garde le fond et le pied du
     * thème, ce qui est le seul sens qui rende `null` utilisable comme
     * « hérite ». Celles d'une publication sont validées à l'écriture
     * (`PostInputFactory::colorOrNull`), mais pas celles du thème, dont la
     * config n'est contrôlée nulle part en entrée : le filtre hexadécimal de
     * `surfaceColor()` est donc la seule garde avant le `<style>` public.
     *
     * @param array<string, string|null> $overrides couleurs par clé de surface, cf. self::SURFACES
     */
    public function frontendSurfacesCss(array $overrides = []): string
    {
        $config = $this->themeContext->activeTheme()?->getConfig() ?? [];
        $rules = [];

        foreach (self::SURFACES as $key => $selector) {
            // Le vide vaut l'absence des deux côtés, et pas seulement `null` :
            // sans ça une chaîne vide passerait le `??` et éteindrait la
            // couleur du thème au lieu de la laisser passer.
            $color = $this->surfaceColor($overrides[$key] ?? null)
                ?? $this->surfaceColor($config[$key] ?? null);

            if (null === $color) {
                continue;
            }

            $rules[] = $this->surfaceRule($selector, $color);
        }

        return implode('', $rules);
    }

    /**
     * The page's own background rule, under a selector the caller chooses
     * instead of `html[data-theme]`.
     *
     * Written for the banner preview in the post editor: that preview is a
     * Twig fragment injected into the backend's own DOM, which never carries
     * `html[data-theme]` - so a title with no colour of its own rendered in
     * whatever the backend's light or dark mode happened to be, not the one
     * the public page actually shows. Scoped to a class the preview's own
     * wrapper carries, so it never leaks onto the rest of the admin screen.
     *
     * Empty when the theme sets no page background - the preview then falls
     * back to the backend's own colours, which is what an unconfigured public
     * page does too.
     */
    public function previewSurfaceCss(string $selector): string
    {
        $color = $this->surfaceColor($this->themeContext->activeTheme()?->getConfig()['background_color'] ?? null);

        return null !== $color ? $this->surfaceRule($selector, $color) : '';
    }

    private function surfaceRule(string $selector, string $color): string
    {
        $declarations = ['--th-surface-bg: '.$color.';', '--th-bg: '.$color.';'];
        foreach ($this->surfaceContrast->tokensFor($color) as $token => $value) {
            $declarations[] = $token.': '.$value.';';
        }

        return $selector.'{'.implode('', $declarations).'}';
    }

    /**
     * Une couleur de surface utilisable, ou null.
     *
     * Hexadécimal strict, comme les couleurs de survol : la valeur finit dans
     * un `<style>` servi à tous les visiteurs, et une chaîne libre y fermerait
     * la règle pour écrire la suite de la page.
     */
    private function surfaceColor(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $color = mb_trim($raw);

        return 1 === preg_match(ThemeContext::HEX_COLOR, $color) ? $color : null;
    }

    /**
     * The `--th-accent-*` declarations of a colour's palette, one per stop.
     *
     * @return list<string>
     */
    private function accentScale(string $color): array
    {
        $declarations = [];
        foreach ($this->primaryColorPalette->generate($color) as $stop => $value) {
            $declarations[] = sprintf('--th-accent-%s: %s;', $stop, $value);
        }

        return $declarations;
    }
}
