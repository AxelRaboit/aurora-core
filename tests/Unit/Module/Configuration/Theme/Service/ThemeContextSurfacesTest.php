<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\Service;

use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Configuration\Theme\Service\PrimaryColorPalette;
use Aurora\Module\Configuration\Theme\Service\SurfaceContrast;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Configuration\Theme\Service\ThemeStyleRenderer;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The CSS produced here is the only thing that separates a color chosen in
 * the suite from an unreadable public page. What is checked: that an
 * unconfigured surface emits nothing, and that a configured surface takes its
 * whole set of tokens with it rather than the background alone.
 */
#[AllowMockObjectsWithoutExpectations]
final class ThemeContextSurfacesTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function contextWithConfig(array $config): ThemeContext
    {
        $theme = $this->createMock(ThemeInterface::class);
        $theme->method('getConfig')->willReturn($config);

        $repository = $this->createMock(ThemeRepository::class);
        $repository->method('findActive')->willReturn($theme);

        return new ThemeContext(
            $repository,
            $this->createMock(DocumentRepository::class),
            // The next two are `final` so they cannot be doubled, and the
            // tested method does not touch them: real instances will do.
            new PrimaryColorPalette(),
            new SurfaceContrast(),
            new DocumentUrlGenerator($this->createMock(UrlGeneratorInterface::class)),
        );
    }

    public function testAThemeWithoutColoursEmitsNothing(): void
    {
        // The historical look stays the default, with no value to maintain.
        self::assertSame('', $this->stylesOf($this->contextWithConfig([]))->frontendSurfacesCss());
    }

    public function testABlankColourIsTreatedAsUnset(): void
    {
        self::assertSame('', $this->stylesOf($this->contextWithConfig(['background_color' => '   ']))->frontendSurfacesCss());
    }

    /**
     * A theme's config is validated nowhere on write, and this CSS goes into
     * a public `<style>`: a free value there would close the tag.
     */
    public function testASurfaceColourThatIsNotHexNeverReachesTheStyleTag(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([
            'background_color' => 'red;}</style><script>alert(1)</script>',
            'header_color' => 'red',
        ]))->frontendSurfacesCss();

        self::assertSame('', $css);
        self::assertSame('', $this->stylesOf($this->contextWithConfig(['background_color' => 'red;}</style>']))->previewSurfaceCss('.preview'));
    }

    public function testACustomPropertyKeepsItsValueButCannotBreakOut(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([
            '--radius' => '0.75rem',
            '--font' => 'var(--th-font, system-ui)',
            '--evil' => 'x;}</style><script>alert(1)</script>',
            '--comment' => 'a /* b',
            '--x;color:red' => '1px',
            'plain' => 'ignored',
        ]))->cssVariableOverrides();

        self::assertSame('--radius: 0.75rem; --font: var(--th-font, system-ui);', $css);
    }

    public function testTheBackgroundRuleTargetsThePage(): void
    {
        $css = $this->stylesOf($this->contextWithConfig(['background_color' => '#0f172a']))->frontendSurfacesCss();

        self::assertStringStartsWith('html[data-theme]{', $css);
        self::assertStringContainsString('--th-bg: #0f172a;', $css);
    }

    public function testTheThemesTextAndLineColoursReachEverySurface(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([
            'background_color' => '#130918',
            'header_color' => '#1b1128',
            'text_color' => '#ece2d0',
            'line_color' => '#3a2a55',
        ]))->frontendSurfacesCss();

        // Once for the page, once for the top bar.
        self::assertSame(2, mb_substr_count($css, '--th-primary: #ece2d0;'));
        self::assertSame(2, mb_substr_count($css, '--color-border: #3a2a55;'));
    }

    public function testATextOrLineColourThatIsNotHexNeverReachesTheStylesheet(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([
            'background_color' => '#130918',
            'text_color' => 'red;}body{display:none',
        ]))->frontendSurfacesCss();

        self::assertStringNotContainsString('display:none', $css);
        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $css);
    }

    public function testTheValidationHeadingAndPictogramColoursNeedNoSurface(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([
            'success_color' => '#34d399',
            'heading_color' => '#ece2d0',
            'icon_color' => '#987aff',
            'figure_color' => '#ece2d0',
        ]))->frontendSurfacesCss();

        self::assertStringContainsString('--th-figure: #ece2d0;', $css);

        self::assertStringContainsString('--th-success: #34d399;', $css);
        self::assertStringContainsString('--th-success-soft: color-mix(in oklab, #34d399 15%, transparent);', $css);
        self::assertStringContainsString('--th-heading: #ece2d0;', $css);
        self::assertStringContainsString('--th-icon: #987aff;', $css);
    }

    public function testAThemeWithoutThemEmitsNoInkRule(): void
    {
        self::assertSame('', $this->stylesOf($this->contextWithConfig([]))->frontendInkCss());
    }

    public function testACardColourReplacesTheDerivedCards(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([
            'background_color' => '#130918',
            'card_color' => '#241838',
        ]))->frontendSurfacesCss();

        self::assertStringContainsString('--th-surface: #241838;', $css);
    }

    /** @return iterable<string, array{array<string, string>, ?string, ?string}> */
    public static function iconSettings(): iterable
    {
        yield 'untouched keeps the file colour' => [[], null, null];
        yield 'accent follows the main colour' => [['icon_color' => 'accent'], 'accent', null];
        yield 'a hex is a colour of its own' => [['icon_color' => '#987aff'], 'custom', '#987aff'];
        yield 'anything else is ignored' => [['icon_color' => 'red'], null, null];
    }

    /** @param array<string, string> $config */
    #[DataProvider('iconSettings')]
    public function testThePictogramSetting(array $config, ?string $tint, ?string $colour): void
    {
        $context = $this->contextWithConfig($config);

        self::assertSame($tint, $context->iconTint());
        self::assertSame($colour, $context->iconColor());
    }

    public function testADarkSurfaceCarriesItsWholeTokenSetNotJustTheBackground(): void
    {
        $css = $this->stylesOf($this->contextWithConfig(['background_color' => '#0f172a']))->frontendSurfacesCss();

        // Without these three, the menu labels and the separators would
        // disappear on the dark background without anything flagging it.
        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $css);
        self::assertStringContainsString('--th-secondary: rgb(156 163 175);', $css);
        self::assertStringContainsString('--color-border: oklch(0.451 0.040 265.755);', $css);
    }

    public function testALightSurfaceKeepsDarkText(): void
    {
        $css = $this->stylesOf($this->contextWithConfig(['background_color' => '#fef9c3']))->frontendSurfacesCss();

        self::assertStringContainsString('--th-primary: rgb(17 24 39);', $css);
    }

    public function testTheHeaderRuleIsScopedAndRedefinesTheDropdownBackground(): void
    {
        $css = $this->stylesOf($this->contextWithConfig(['header_color' => '#111827']))->frontendSurfacesCss();

        self::assertStringContainsString('html[data-theme] .aurora-surface-header{', $css);
        // --th-surface-bg paints the bar, --th-bg follows the dropdown menu
        // panels, which are rendered in `bg-bg`.
        self::assertStringContainsString('--th-surface-bg: #111827;', $css);
        self::assertStringContainsString('--th-bg: #111827;', $css);
    }

    public function testEachSurfaceIsDecidedOnItsOwn(): void
    {
        // A dark topbar on a light page: the two rules coexist and do not
        // carry the same set of text colors.
        $css = $this->stylesOf($this->contextWithConfig([
            'background_color' => '#ffffff',
            'header_color' => '#0f172a',
        ]))->frontendSurfacesCss();

        [$page, $header] = explode('html[data-theme] .aurora-surface-header{', $css);

        self::assertStringContainsString('--th-primary: rgb(17 24 39);', $page);
        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $header);
    }

    public function testAllThreeSurfacesCanBeSetTogether(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([
            'background_color' => '#ffffff',
            'header_color' => '#0f172a',
            'footer_color' => '#1f2937',
        ]))->frontendSurfacesCss();

        self::assertStringContainsString('.aurora-surface-header{', $css);
        self::assertStringContainsString('.aurora-surface-footer{', $css);
        self::assertSame(3, mb_substr_count($css, '--th-primary'));
    }

    public function testAnUnrelatedConfigKeyIsIgnored(): void
    {
        // `config` also carries primary_color, the logo, the content width.
        $css = $this->stylesOf($this->contextWithConfig([
            'primary_color' => '#10b981',
            'content_width' => 'wide',
        ]))->frontendSurfacesCss();

        self::assertSame('', $css);
    }

    // ── Surcharges de la page en cours de rendu ───────────────────────────────

    public function testAnOverridePaintsASurfaceTheThemeLeftUnset(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([]))->frontendSurfacesCss(['header_color' => '#0f172a']);

        self::assertStringContainsString('html[data-theme] .aurora-surface-header{', $css);
        self::assertStringContainsString('--th-surface-bg: #0f172a;', $css);
    }

    public function testAnOverrideWinsOverTheThemeOnThatSurface(): void
    {
        $css = $this->stylesOf($this->contextWithConfig(['header_color' => '#ffffff']))
            ->frontendSurfacesCss(['header_color' => '#0f172a']);

        self::assertStringContainsString('--th-surface-bg: #0f172a;', $css);
        self::assertStringNotContainsString('#ffffff', $css);
    }

    public function testANullOverrideLeavesTheThemeStanding(): void
    {
        // The field's contract: empty means "inherit", not "turn off".
        $css = $this->stylesOf($this->contextWithConfig(['header_color' => '#0f172a']))
            ->frontendSurfacesCss(['header_color' => null]);

        self::assertStringContainsString('--th-surface-bg: #0f172a;', $css);
    }

    public function testABlankOverrideLeavesTheThemeStandingToo(): void
    {
        // Empty is not a choice, even when it arrives as a string: without
        // that a post would erase the theme color without asking for it.
        $css = $this->stylesOf($this->contextWithConfig(['header_color' => '#0f172a']))
            ->frontendSurfacesCss(['header_color' => '   ']);

        self::assertStringContainsString('--th-surface-bg: #0f172a;', $css);
    }

    public function testOverridingOneSurfaceLeavesTheOthersToTheTheme(): void
    {
        // A post that only picks its topbar keeps the theme background,
        // which is what makes the per-surface override usable.
        $css = $this->stylesOf($this->contextWithConfig([
            'background_color' => '#ffffff',
            'footer_color' => '#1f2937',
        ]))->frontendSurfacesCss(['header_color' => '#0f172a']);

        self::assertStringContainsString('html[data-theme]{', $css);
        self::assertStringContainsString('.aurora-surface-header{', $css);
        self::assertStringContainsString('.aurora-surface-footer{', $css);
        self::assertSame(3, mb_substr_count($css, '--th-primary'));
    }

    public function testAnOverriddenSurfaceCarriesItsContrastTokens(): void
    {
        // Contrast is what tells "repaint" apart from "make unreadable": the
        // override must go through the same computation.
        $css = $this->stylesOf($this->contextWithConfig([]))->frontendSurfacesCss(['background_color' => '#0f172a']);

        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $css);
    }

    public function testNoOverrideBehavesExactlyAsBefore(): void
    {
        $withEmpty = $this->stylesOf($this->contextWithConfig(['background_color' => '#fef9c3']))->frontendSurfacesCss([]);
        $withNone = $this->stylesOf($this->contextWithConfig(['background_color' => '#fef9c3']))->frontendSurfacesCss();

        self::assertSame($withNone, $withEmpty);
    }

    // ── The post editor's own preview, under its own selector ─────────────────

    /**
     * The regression this method exists for: a banner preview rendered in
     * whatever colour the suite's own theme happened to be, not the one the
     * public page actually shows a title with no colour of its own.
     */
    public function testThePreviewRuleCarriesThePageBackgroundUnderItsOwnSelector(): void
    {
        $css = $this->stylesOf($this->contextWithConfig(['background_color' => '#0f172a']))
            ->previewSurfaceCss('.aurora-banner-preview[data-theme]');

        self::assertStringStartsWith('.aurora-banner-preview[data-theme]{', $css);
        self::assertStringContainsString('--th-bg: #0f172a;', $css);
        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $css);
    }

    public function testThePreviewRuleIgnoresTheHeaderAndFooterSurfaces(): void
    {
        // The banner is the page's own background, not the topbar or the
        // footer - a preview scoped to the wrong surface would still be wrong.
        $css = $this->stylesOf($this->contextWithConfig([
            'header_color' => '#111827',
            'footer_color' => '#1f2937',
        ]))->previewSurfaceCss('.aurora-banner-preview[data-theme]');

        self::assertSame('', $css);
    }

    public function testAnUnconfiguredThemeEmitsNoPreviewRule(): void
    {
        // The same "light page, dark text" default an unconfigured public
        // page falls back to - nothing to override here either.
        self::assertSame('', $this->stylesOf($this->contextWithConfig([]))->previewSurfaceCss('.aurora-banner-preview[data-theme]'));
    }

    // ── A publication's own accent, scoped to its own selector ────────────────

    public function testNoAccentColourEmitsNothing(): void
    {
        self::assertSame('', $this->stylesOf($this->contextWithConfig([]))->postAccentCss('.aurora-post-accent', null));
    }

    public function testABlankAccentColourEmitsNothingToo(): void
    {
        self::assertSame('', $this->stylesOf($this->contextWithConfig([]))->postAccentCss('.aurora-post-accent', '   '));
    }

    public function testAnAccentColourGeneratesTheFullScaleUnderItsOwnSelector(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([]))->postAccentCss('.aurora-post-accent', '#b45309');

        self::assertStringStartsWith('.aurora-post-accent{', $css);
        self::assertStringContainsString('--th-accent-500:', $css);
        self::assertStringContainsString('--th-accent-50:', $css);
        self::assertStringContainsString('--th-accent-950:', $css);
    }

    /**
     * `--th-accent` is resolved on :root then inherited as a value: the
     * palette alone left `text-accent` and `bg-accent` at the theme color.
     */
    public function testAnAccentColourAlsoRepointsTheUnnumberedAccent(): void
    {
        $css = $this->stylesOf($this->contextWithConfig([]))->postAccentCss('.aurora-post-accent', '#b45309');

        self::assertStringContainsString('--th-accent: var(--th-accent-500);', $css);
        self::assertStringContainsString('.dark .aurora-post-accent{--th-accent: var(--th-accent-400);', $css);
    }

    public function testACalloutColourRepaintsItsTypeOnly(): void
    {
        $css = $this->stylesOf($this->contextWithConfig(['callout_success_color' => '#34d399']))->frontendSurfacesCss();

        self::assertSame('html[data-theme] .callout-block--success,html[data-theme] .callout--success{--callout-color:#34d399}', $css);
    }

    public function testACalloutColourThatIsNotHexNeverReachesTheStyleTag(): void
    {
        self::assertSame('', $this->stylesOf($this->contextWithConfig([
            'callout_info_color' => 'red}</style><script>alert(1)</script>',
            'callout_accent_color' => '#000000',
        ]))->frontendSurfacesCss());
    }

    public function testAResetReadsTheActiveThemeAgain(): void
    {
        $repository = $this->createMock(ThemeRepository::class);
        $repository->expects(self::exactly(2))->method('findActive')->willReturn(null);

        $context = new ThemeContext(
            $repository,
            $this->createMock(DocumentRepository::class),
            new PrimaryColorPalette(),
            new SurfaceContrast(),
            new DocumentUrlGenerator($this->createMock(UrlGeneratorInterface::class)),
        );
        $context->activeTheme();
        $context->activeTheme();
        $context->reset();
        $context->activeTheme();
    }

    private function stylesOf(ThemeContext $context): ThemeStyleRenderer
    {
        return new ThemeStyleRenderer($context, new PrimaryColorPalette(), new SurfaceContrast());
    }
}
