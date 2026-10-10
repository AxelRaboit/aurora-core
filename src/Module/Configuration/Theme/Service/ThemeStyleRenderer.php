<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Service;

use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;
use Throwable;

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
     * Keys of `Theme::config` carrying the colors of the three public surfaces,
     * mapped to the selector they dress.
     */
    private const array SURFACES = [
        'background_color' => 'html[data-theme]',
        'header_color' => 'html[data-theme] .aurora-surface-header',
        'footer_color' => 'html[data-theme] .aurora-surface-footer',
    ];

    /**
     * The callout types whose color the theme can take over, each under the
     * key `callout_<type>_color`. `accent` is not there: it already follows
     * the main color. The original colors live in
     * `content-blocks.css`.
     */
    public const array CALLOUT_TYPES = [
        'info', 'success', 'warning', 'danger', 'tip', 'note', 'question',
        'important', 'update', 'rose', 'lime', 'amber', 'fuchsia',
    ];

    /**
     * The rule that sets the application in the theme's font, placed in the
     * `<head>` by `primary_color_style.html.twig`.
     *
     * It redefines `--th-font-sans`, which `--font-sans` only points to:
     * `body` uses it directly and the `font-sans` utility copies its
     * `var(...)`, so the whole page follows. A theme left on the default emits
     * nothing: the default already lives in `theme.css`, and a rule repeating
     * a default is a second copy to keep up to date.
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
     * A publication's choice, bounded to its content like its accent.
     *
     * Set on the container **and** each of its descendants, under
     * `html[data-theme]`: the theme's neutral mode is set element by element,
     * and only a more specific rule at the same level replaces it. `initial`
     * clears the theme's value, and the fallback to the accent takes over.
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
    /**
     * The theme on an error page: its surfaces, its raw overrides, its accent
     * and its font - what the public layout's head poses, in one string.
     *
     * Never throws. A 500 or a 503 can be rendered while the database is the
     * very thing that failed, and an error page that errors leaves the reader
     * with Symfony's bare fallback: the page then keeps the neutral colours
     * it carries itself.
     */
    public function errorPageCss(): string
    {
        try {
            $overrides = $this->cssVariableOverrides();

            return $this->frontendSurfacesCss()
                .('' !== $overrides ? 'html[data-theme]{'.$overrides.'}' : '')
                .$this->primaryColorCss()
                .$this->fontFamilyCss();
        } catch (Throwable) {
            return '';
        }
    }

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

        // --th-accent is resolved once on :root and inherited as is: without
        // setting it again here, `text-accent` and `bg-accent` kept the
        // theme's color in the middle of a page that had chosen another one.
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

        // Filtered on read, since it is not on write: a custom property name,
        // and a value that can close neither the declaration, nor the rule,
        // nor the `<style>` tag carrying it.
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
     * The CSS that colors the public frontend from the colors chosen in the
     * theme screen.
     *
     * An unconfigured surface emits no rule: the historical look (light
     * background, dark text, transparent topbar and footer) is therefore the
     * default behavior, with no value to maintain anywhere.
     *
     * Each rule sets the background **and** the matching contrasted token
     * set, in the same place. Custom properties being inherited, everything
     * the surface holds follows: labels, discreet mentions, borders, and the
     * dropdown menu panels, painted in `bg-bg`, which thus end up on their
     * topbar's background rather than the page's.
     *
     * `$overrides` carries the colors of the page being rendered, when it has
     * any - a publication can dress its three surfaces for itself alone.
     * The substitution happens surface by surface, not as a block: a
     * publication that only picks its topbar keeps the theme's background and
     * footer, which is the only meaning that makes `null` usable as
     * "inherit". A publication's colors are validated on write
     * (`PostInputFactory::colorOrNull`), but not the theme's, whose config is
     * checked nowhere on input: the hexadecimal filter of `surfaceColor()` is
     * therefore the only guard before the public `<style>`.
     *
     * @param array<string, string|null> $overrides colors by surface key (cf. self::SURFACES), and
     *                                              those of the text, lines, cards, headings and
     *                                              figures (cf. PostColorOverrides::KEYS)
     */
    public function frontendSurfacesCss(array $overrides = []): string
    {
        $config = $this->themeContext->activeTheme()?->getConfig() ?? [];
        $rules = [];

        foreach (self::SURFACES as $key => $selector) {
            // Empty counts as absent on both sides, not only `null`: without
            // that an empty string would pass the `??` and switch off the
            // theme's color instead of letting it through.
            $color = $this->surfaceColor($overrides[$key] ?? null)
                ?? $this->surfaceColor($config[$key] ?? null);

            if (null === $color) {
                continue;
            }

            $rules[] = $this->surfaceRule($selector, $color, $overrides);
        }

        return implode('', $rules).$this->frontendInkCss($overrides).$this->calloutCss();
    }

    /**
     * The callout colors taken over by the theme, one rule per type set.
     * `html[data-theme]` wins over the original rules of
     * `content-blocks.css`, which only have one class.
     */
    public function calloutCss(): string
    {
        $config = $this->themeContext->activeTheme()?->getConfig() ?? [];
        $rules = '';
        foreach (self::CALLOUT_TYPES as $type) {
            $color = $this->surfaceColor($config['callout_'.$type.'_color'] ?? null);
            if (null !== $color) {
                $rules .= sprintf('html[data-theme] .callout-block--%1$s,html[data-theme] .callout--%1$s{--callout-color:%2$s}', $type, $color);
            }
        }

        return $rules;
    }

    /**
     * The theme's colours that do not depend on a surface: the tick of a
     * validated line, the headings of the content, the key figures, the
     * pictograms of the cards. Set once on the page, inherited everywhere, whatever surface
     * the element sits on - so unlike the ink colours, they need no page
     * background to take effect.
     *
     * Empty when the theme sets none of them: the defaults live in theme.css.
     *
     * A publication's own heading and figure colours pass before the theme's,
     * key by key, like its surfaces.
     *
     * @param array<string, string|null> $overrides
     */
    public function frontendInkCss(array $overrides = []): string
    {
        $config = $this->themeContext->activeTheme()?->getConfig() ?? [];
        $declarations = [];

        if (null !== $success = $this->surfaceColor($config['success_color'] ?? null)) {
            $declarations[] = '--th-success: '.$success.';';
            $declarations[] = '--th-success-soft: color-mix(in oklab, '.$success.' 15%, transparent);';
        }

        if (null !== $heading = $this->configured('heading_color', $overrides, $config)) {
            $declarations[] = '--th-heading: '.$heading.';';
        }

        if (null !== $figure = $this->configured('figure_color', $overrides, $config)) {
            $declarations[] = '--th-figure: '.$figure.';';
        }

        if (null !== $icon = $this->themeContext->iconColor()) {
            $declarations[] = '--th-icon: '.$icon.';';
        }

        return [] === $declarations ? '' : 'html[data-theme]{'.implode('', $declarations).'}';
    }

    /**
     * The page's own background rule, under a selector the caller chooses
     * instead of `html[data-theme]`.
     *
     * Written for the banner preview in the post editor: that preview is a
     * Twig fragment injected into the suite's own DOM, which never carries
     * `html[data-theme]` - so a title with no colour of its own rendered in
     * whatever the suite's light or dark mode happened to be, not the one
     * the public page actually shows. Scoped to a class the preview's own
     * wrapper carries, so it never leaks onto the rest of the admin screen.
     *
     * Empty when the theme sets no page background - the preview then falls
     * back to the suite's own colours, which is what an unconfigured public
     * page does too.
     */
    public function previewSurfaceCss(string $selector): string
    {
        $color = $this->surfaceColor($this->themeContext->activeTheme()?->getConfig()['background_color'] ?? null);

        return null !== $color ? $this->surfaceRule($selector, $color) : '';
    }

    /** @param array<string, string|null> $overrides a publication's own colours, cf. frontendSurfacesCss() */
    private function surfaceRule(string $selector, string $color, array $overrides = []): string
    {
        $config = $this->themeContext->activeTheme()?->getConfig() ?? [];
        $tokens = [
            ...$this->surfaceContrast->tokensFor($color),
            // The theme's own text and line colours, over the ones the
            // background implied: an off-white instead of pure white, a
            // violet rule under the top bar instead of the derived one. A
            // publication's own pass before the theme's.
            ...$this->surfaceContrast->inkTokensFor(
                $color,
                $this->configured('text_color', $overrides, $config),
                $this->configured('line_color', $overrides, $config),
                $this->configured('card_line_color', $overrides, $config),
            ),
            ...$this->surfaceContrast->cardTokensFor($color, $this->configured('card_color', $overrides, $config)),
        ];

        $declarations = ['--th-surface-bg: '.$color.';', '--th-bg: '.$color.';'];
        foreach ($tokens as $token => $value) {
            $declarations[] = $token.': '.$value.';';
        }

        return $selector.'{'.implode('', $declarations).'}';
    }

    /**
     * The color of a key: the publication's if it sets one, otherwise the
     * theme's, otherwise nothing. Both go through the same filter.
     *
     * @param array<string, string|null> $overrides
     * @param array<string, mixed>       $config
     */
    private function configured(string $key, array $overrides, array $config): ?string
    {
        return $this->surfaceColor($overrides[$key] ?? null) ?? $this->surfaceColor($config[$key] ?? null);
    }

    /**
     * A usable surface color, or null.
     *
     * Strict hexadecimal, like the hover colors: the value ends up in a
     * `<style>` served to every visitor, and a free string could close the
     * rule there and write the rest of the page.
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
