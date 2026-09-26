<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\Service;

use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Configuration\Theme\Service\PrimaryColorPalette;
use Aurora\Module\Configuration\Theme\Service\SurfaceContrast;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Un thème qui n'a rien choisi garde des survols en couleur principale : c'est
 * l'apparence de tous les sites existants, et elle ne doit pas bouger.
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
        self::assertSame('', $this->contextWithConfig([])->highlightCss());
        self::assertSame('', $this->contextWithConfig(['highlight' => 'neutral', 'highlight_color' => '#ff0000'])->highlightCss());
    }

    public function testACustomColourBecomesTheHighlightToken(): void
    {
        $context = $this->contextWithConfig(['highlight' => 'custom', 'highlight_color' => '#c2410c']);

        self::assertSame('custom', $context->highlight());
        self::assertSame('html[data-theme]{--th-highlight: #c2410c;}', $context->highlightCss());
    }

    public function testAPublicationThatInheritsEmitsNothing(): void
    {
        $context = $this->contextWithConfig([]);

        self::assertSame('', $context->postHighlightCss('.p', null, null));
        self::assertSame('', $context->postHighlightCss('.p', 'grey', null));
        self::assertSame('', $context->postHighlightCss('.p', 'custom', 'red;}</style>'));
    }

    /**
     * `initial` rend au jeton sa valeur invalide garantie : le repli sur
     * l'accent reprend alors, même sous un thème neutre ou personnalisé.
     */
    public function testAPublicationCanBringTheAccentBack(): void
    {
        self::assertSame(
            'html[data-theme] .p,html[data-theme] .p *{--th-highlight: initial;}',
            $this->contextWithConfig(['highlight' => 'neutral'])->postHighlightCss('.p', 'accent', null),
        );
    }

    public function testAPublicationCanGoNeutralOrCustom(): void
    {
        $context = $this->contextWithConfig([]);

        self::assertSame('html[data-theme] .p,html[data-theme] .p *{--th-highlight: var(--th-primary);}', $context->postHighlightCss('.p', 'neutral', null));
        self::assertSame('html[data-theme] .p,html[data-theme] .p *{--th-highlight: #b45309;}', $context->postHighlightCss('.p', 'custom', '#b45309'));
    }

    /**
     * La couleur part dans une balise `<style>` : tout ce qui n'est pas un
     * hexadécimal est refusé, et le thème retombe sur l'accent plutôt que de
     * rendre des survols sans couleur.
     */
    public function testACustomThemeWithoutAUsableColourKeepsTheAccent(): void
    {
        foreach ([null, '', 'red', '#fff', '#c2410c;}</style><script>alert(1)</script>'] as $color) {
            $context = $this->contextWithConfig(['highlight' => 'custom', 'highlight_color' => $color]);

            self::assertSame('accent', $context->highlight());
            self::assertSame('', $context->highlightCss());
        }
    }
}
