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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A theme that has chosen nothing keeps hovers in the main color: that is the
 * look of every existing site, and it must not move.
 */
#[AllowMockObjectsWithoutExpectations]
final class ThemeContextHighlightTest extends TestCase
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
            new PrimaryColorPalette(),
            new SurfaceContrast(),
            new DocumentUrlGenerator($this->createMock(UrlGeneratorInterface::class)),
        );
    }

    public function testAThemeWithoutAChoiceKeepsTheAccent(): void
    {
        self::assertSame('accent', $this->contextWithConfig([])->highlight());
    }

    public function testANeutralThemeIsNeutral(): void
    {
        self::assertSame('neutral', $this->contextWithConfig(['highlight' => 'neutral'])->highlight());
    }

    public function testAnUnknownValueFallsBackToTheAccent(): void
    {
        self::assertSame('accent', $this->contextWithConfig(['highlight' => 'grey'])->highlight());
    }

    public function testOnlyACustomThemeEmitsAColour(): void
    {
        self::assertSame('', $this->stylesOf($this->contextWithConfig([]))->highlightCss());
        self::assertSame('', $this->stylesOf($this->contextWithConfig(['highlight' => 'neutral', 'highlight_color' => '#ff0000']))->highlightCss());
    }

    public function testACustomColourBecomesTheHighlightToken(): void
    {
        $context = $this->contextWithConfig(['highlight' => 'custom', 'highlight_color' => '#c2410c']);

        self::assertSame('custom', $context->highlight());
        self::assertSame('html[data-theme]{--th-highlight: #c2410c;}', $this->stylesOf($context)->highlightCss());
    }

    /** The active entry of the top bar: the same three modes, read from their own keys. */
    public function testTheMenuMarkerHasItsOwnSetting(): void
    {
        self::assertSame('accent', $this->contextWithConfig([])->menuActive());
        self::assertSame('', $this->stylesOf($this->contextWithConfig([]))->menuActiveCss());
        self::assertSame('neutral', $this->contextWithConfig(['menu_active' => 'neutral', 'highlight' => 'custom'])->menuActive());

        $custom = $this->contextWithConfig(['menu_active' => 'custom', 'menu_active_color' => '#f59e0b']);
        self::assertSame('html[data-theme]{--th-menu-active: #f59e0b;}', $this->stylesOf($custom)->menuActiveCss());

        $bad = $this->contextWithConfig(['menu_active' => 'custom', 'menu_active_color' => 'red;}</style>']);
        self::assertSame('accent', $bad->menuActive());
        self::assertSame('', $this->stylesOf($bad)->menuActiveCss());
    }

    public function testAPublicationThatInheritsEmitsNothing(): void
    {
        $context = $this->contextWithConfig([]);

        self::assertSame('', $this->stylesOf($context)->postHighlightCss('.p', null, null));
        self::assertSame('', $this->stylesOf($context)->postHighlightCss('.p', 'grey', null));
        self::assertSame('', $this->stylesOf($context)->postHighlightCss('.p', 'custom', 'red;}</style>'));
    }

    /**
     * `initial` gives the token back its guaranteed invalid value: the
     * fallback to the accent then resumes, even under a neutral or custom theme.
     */
    public function testAPublicationCanBringTheAccentBack(): void
    {
        self::assertSame(
            'html[data-theme] .p,html[data-theme] .p *{--th-highlight: initial;}',
            $this->stylesOf($this->contextWithConfig(['highlight' => 'neutral']))->postHighlightCss('.p', 'accent', null),
        );
    }

    public function testAPublicationCanGoNeutralOrCustom(): void
    {
        $context = $this->contextWithConfig([]);

        self::assertSame('html[data-theme] .p,html[data-theme] .p *{--th-highlight: var(--th-primary);}', $this->stylesOf($context)->postHighlightCss('.p', 'neutral', null));
        self::assertSame('html[data-theme] .p,html[data-theme] .p *{--th-highlight: #b45309;}', $this->stylesOf($context)->postHighlightCss('.p', 'custom', '#b45309'));
    }

    /**
     * The color goes into a `<style>` tag: anything that is not a hexadecimal
     * is refused, and the theme falls back to the accent rather than rendering
     * hovers without a color.
     */
    public function testACustomThemeWithoutAUsableColourKeepsTheAccent(): void
    {
        foreach ([null, '', 'red', '#fff', '#c2410c;}</style><script>alert(1)</script>'] as $color) {
            $context = $this->contextWithConfig(['highlight' => 'custom', 'highlight_color' => $color]);

            self::assertSame('accent', $context->highlight());
            self::assertSame('', $this->stylesOf($context)->highlightCss());
        }
    }

    private function stylesOf(ThemeContext $context): ThemeStyleRenderer
    {
        return new ThemeStyleRenderer($context, new PrimaryColorPalette(), new SurfaceContrast());
    }
}
