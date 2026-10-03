<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Service;

use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Deprecated;
use Symfony\Contracts\Service\ResetInterface;

final class ThemeContext implements ResetInterface
{
    /**
     * Default primary colour seed when the active theme has none configured.
     *
     * Emerald. The palette generator pins each stop's lightness, so the hue can
     * move without dragging contrast with it: white on `accent-600` measures
     * 5.52:1 here against 6.76:1 for the indigo it replaces - both above the
     * 4.5:1 a button label needs.
     */
    public const string DEFAULT_PRIMARY_COLOR = '#10b981';

    private ?ThemeInterface $cachedTheme = null;

    private bool $resolved = false;

    /** @var list<string> */
    public const array CONTENT_WIDTHS = ['narrow', 'wide', 'full'];

    public const array HIGHLIGHTS = ['accent', 'neutral', 'custom'];

    /** Strict `#rrggbb`: every colour of a theme ends up in a public `<style>`. */
    public const string HEX_COLOR = '/^#[0-9a-fA-F]{6}$/';

    private ?ThemeStyleRenderer $styles = null;

    public function __construct(
        private readonly ThemeRepository $themeRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly PrimaryColorPalette $primaryColorPalette,
        private readonly SurfaceContrast $surfaceContrast,
        private readonly DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    /**
     * Oublie le thème lu : un worker qui enverrait des e-mails pendant des
     * heures garderait sinon celui d'avant un changement de thème.
     */
    public function reset(): void
    {
        $this->resolved = false;
        $this->cachedTheme = null;
    }

    public function activeTheme(): ?ThemeInterface
    {
        if (!$this->resolved) {
            $this->cachedTheme = $this->themeRepository->findActive();
            $this->resolved = true;
        }

        return $this->cachedTheme;
    }

    public function activeThemeSlug(): string
    {
        return $this->activeTheme()?->getSlug() ?? 'default';
    }

    public function headerLogoUrl(): ?string
    {
        $theme = $this->activeTheme();

        return $theme instanceof ThemeInterface ? $this->headerLogoUrlFor($theme) : null;
    }

    /**
     * The address of a theme's header logo, active or not: the themes screen
     * shows the picked picture on each theme's form, not only the live one's.
     */
    public function headerLogoUrlFor(ThemeInterface $theme): ?string
    {
        $rawId = $theme->getConfig()['header_logo_media_id'] ?? '';
        if (!is_string($rawId) || '' === $rawId) {
            return null;
        }

        $document = $this->documentRepository->find((int) $rawId);

        return $this->documentUrlGenerator->publicUrl($document);
    }

    public function headerCustomText(): ?string
    {
        $text = $this->activeTheme()?->getConfig()['header_custom_text'] ?? '';

        return (is_string($text) && '' !== $text) ? $text : null;
    }

    /**
     * Le logo seul sur téléphone : le nom du site (ou le texte personnalisé)
     * passe sous `sm`. Seulement quand un logo existe, sinon l'entête
     * n'aurait plus rien à montrer.
     */
    public function headerTextHiddenOnPhone(): bool
    {
        return 'hidden' === ($this->activeTheme()?->getConfig()['header_text_on_phone'] ?? null)
            && null !== $this->headerLogoUrl();
    }

    /**
     * La barre de lecture en haut des pages publiques. Affichée par défaut :
     * seul un thème qui l'a coupée porte la clé, comme les autres réglages
     * qui ne s'écrivent que lorsqu'ils s'écartent du défaut.
     */
    public function readingProgress(): bool
    {
        return 'hidden' !== ($this->activeTheme()?->getConfig()['reading_progress'] ?? null);
    }

    public function footerText(string $siteName): string
    {
        $custom = $this->activeTheme()?->getConfig()['footer_text'] ?? '';
        $text = (is_string($custom) && '' !== $custom) ? $custom : '© {year} {siteName}';

        return str_replace(['{year}', '{siteName}'], [date('Y'), $siteName], $text);
    }

    /** Active theme's primary colour as hex (falls back to DEFAULT_PRIMARY_COLOR). */
    /**
     * How wide the frontend content column should be.
     *
     * A theme decides its own chrome, but the reading width is the kind of
     * thing an editor wants to change without touching templates - Notion's
     * "full width" toggle is the reference. Stored per theme rather than
     * globally: switching themes should not carry the previous one's layout.
     *
     * @return 'narrow'|'wide'|'full'
     */
    public function contentWidth(): string
    {
        $value = $this->activeTheme()?->getConfig()['content_width'] ?? '';

        return in_array($value, self::CONTENT_WIDTHS, true) ? $value : 'narrow';
    }

    /**
     * The utility classes for {@see contentWidth()}, so every template does not
     * repeat the mapping and drift from it.
     *
     * These class names live in PHP, which Tailwind does not scan - they are
     * kept generated by an `@source inline(…)` in app.css. Adding a width here
     * means adding it there too, or it renders unstyled.
     */
    public function contentWidthClass(): string
    {
        return match ($this->contentWidth()) {
            'wide' => 'max-w-5xl mx-auto',
            'full' => 'max-w-none',
            default => 'max-w-3xl mx-auto',
        };
    }

    /**
     * La famille choisie par le thème actif.
     *
     * Rangée dans le thème plutôt que dans un réglage global, pour la même
     * raison que la largeur de contenu : changer de thème doit emporter sa
     * typographie, pas hériter de celle du précédent.
     */
    public function font(): ThemeFontEnum
    {
        return ThemeFontEnum::fromConfig($this->activeTheme()?->getConfig()['font_family'] ?? null);
    }

    /**
     * Ce que prennent les survols du site public et les repères des cartes
     * (catégorie, flèche) : la couleur principale, ou le texte de la surface
     * sur laquelle ils sont posés. Le neutre sert aux pages qui jouent déjà
     * leurs propres couleurs et qu'un survol vert viendrait contredire. Le
     * personnalisé sans couleur exploitable retombe sur l'accent.
     *
     * @return 'accent'|'neutral'|'custom'
     */
    public function highlight(): string
    {
        return $this->colorMode('highlight', 'highlight_color');
    }

    public function highlightColor(): ?string
    {
        return $this->hexSetting('highlight_color');
    }

    /**
     * The mark under the active entry of the top bar: the primary colour,
     * the text colour of the bar, or a colour of its own. Same three modes
     * as the hovers, and the same fallback when a custom colour is missing.
     *
     * @return 'accent'|'neutral'|'custom'
     */
    public function menuActive(): string
    {
        return $this->colorMode('menu_active', 'menu_active_color');
    }

    public function menuActiveColor(): ?string
    {
        return $this->hexSetting('menu_active_color');
    }

    /**
     * A mode among self::HIGHLIGHTS read from the theme, falling back to the
     * accent - including for `custom` without a usable colour, which would
     * otherwise draw in no colour at all.
     *
     * @return 'accent'|'neutral'|'custom'
     */
    private function colorMode(string $key, string $colorKey): string
    {
        $value = $this->activeTheme()?->getConfig()[$key] ?? '';

        if ('custom' === $value) {
            return null !== $this->hexSetting($colorKey) ? 'custom' : 'accent';
        }

        return in_array($value, self::HIGHLIGHTS, true) ? $value : 'accent';
    }

    /**
     * Strict hex and nothing else: the value lands in a `<style>`, and the
     * theme's configuration is not validated anywhere on the way in.
     */
    private function hexSetting(string $key): ?string
    {
        $value = $this->activeTheme()?->getConfig()[$key] ?? null;

        return is_string($value) && 1 === preg_match(self::HEX_COLOR, $value) ? $value : null;
    }

    /**
     * How the pictograms of the cards are coloured: `accent` follows the
     * theme's main colour, `custom` the colour the theme gives them, and null
     * leaves every SVG in the colour it was drawn in - which is what a site
     * that never touched the setting keeps.
     *
     * @return 'accent'|'custom'|null
     */
    public function iconTint(): ?string
    {
        $value = $this->activeTheme()?->getConfig()['icon_color'] ?? null;

        if ('accent' === $value) {
            return 'accent';
        }

        return null !== $this->hexSetting('icon_color') ? 'custom' : null;
    }

    /** The pictograms' own colour, when the theme gives them one. */
    public function iconColor(): ?string
    {
        return $this->hexSetting('icon_color');
    }

    public function primaryColor(): string
    {
        $value = $this->activeTheme()?->getConfig()['primary_color'] ?? '';

        return is_string($value) && '' !== $value ? $value : self::DEFAULT_PRIMARY_COLOR;
    }

    #[Deprecated(message: 'since 0.9.268, use ThemeStyleRenderer::fontFamilyCss() - the `themeStyles` Twig global.')]
    public function fontFamilyCss(): string
    {
        return $this->styles()->fontFamilyCss();
    }

    #[Deprecated(message: 'since 0.9.268, use ThemeStyleRenderer::postHighlightCss() - the `themeStyles` Twig global.')]
    public function postHighlightCss(string $selector, ?string $highlight, ?string $color): string
    {
        return $this->styles()->postHighlightCss($selector, $highlight, $color);
    }

    #[Deprecated(message: 'since 0.9.268, use ThemeStyleRenderer::highlightCss() - the `themeStyles` Twig global.')]
    public function highlightCss(): string
    {
        return $this->styles()->highlightCss();
    }

    #[Deprecated(message: 'since 0.9.268, use ThemeStyleRenderer::menuActiveCss() - the `themeStyles` Twig global.')]
    public function menuActiveCss(): string
    {
        return $this->styles()->menuActiveCss();
    }

    #[Deprecated(message: 'since 0.9.268, use ThemeStyleRenderer::primaryColorCss() - the `themeStyles` Twig global.')]
    public function primaryColorCss(): string
    {
        return $this->styles()->primaryColorCss();
    }

    #[Deprecated(message: 'since 0.9.268, use ThemeStyleRenderer::postAccentCss() - the `themeStyles` Twig global.')]
    public function postAccentCss(string $selector, ?string $accentColor): string
    {
        return $this->styles()->postAccentCss($selector, $accentColor);
    }

    #[Deprecated(message: 'since 0.9.268, use ThemeStyleRenderer::cssVariableOverrides() - the `themeStyles` Twig global.')]
    public function cssVariableOverrides(): string
    {
        return $this->styles()->cssVariableOverrides();
    }

    #[Deprecated(message: 'since 0.9.268, use ThemeStyleRenderer::frontendSurfacesCss() - the `themeStyles` Twig global.')]
    public function frontendSurfacesCss(array $overrides = []): string
    {
        return $this->styles()->frontendSurfacesCss($overrides);
    }

    #[Deprecated(message: 'since 0.9.268, use ThemeStyleRenderer::previewSurfaceCss() - the `themeStyles` Twig global.')]
    public function previewSurfaceCss(string $selector): string
    {
        return $this->styles()->previewSurfaceCss($selector);
    }

    /**
     * The renderer behind the deprecated delegates above, built here rather
     * than injected: it reads this context, so injecting it would be a cycle.
     */
    private function styles(): ThemeStyleRenderer
    {
        return $this->styles ??= new ThemeStyleRenderer($this, $this->primaryColorPalette, $this->surfaceContrast);
    }
}
