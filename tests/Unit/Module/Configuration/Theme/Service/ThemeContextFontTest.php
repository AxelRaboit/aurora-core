<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\Service;

use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;
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
 * La règle produite ici est ce qui sépare un choix fait dans l'écran de thème
 * d'une application qui reste en Poppins sans rien dire.
 */
#[AllowMockObjectsWithoutExpectations]
final class ThemeContextFontTest extends TestCase
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

    public function testAThemeWithoutAFontEmitsNothing(): void
    {
        // Le défaut vit déjà dans theme.css : une règle qui le répète serait
        // une seconde copie à tenir à jour.
        self::assertSame('', $this->contextWithConfig([])->fontFamilyCss());
        self::assertSame('', $this->contextWithConfig(['font_family' => 'poppins'])->fontFamilyCss());
    }

    public function testAChosenFontOverridesTheVariableTheWholePageReads(): void
    {
        $css = $this->contextWithConfig(['font_family' => 'lora'])->fontFamilyCss();

        // `--th-font-sans` et pas `--font-sans` : la seconde est recopiée
        // littéralement par Tailwind au moment de la compilation, donc la
        // redéfinir au chargement ne changerait rien.
        self::assertStringContainsString('--th-font-sans:', $css);
        self::assertStringContainsString(ThemeFontEnum::Lora->stack(), $css);
        self::assertStringStartsWith(':root{', $css);
    }

    public function testAnUnknownFontLeavesThePageOnTheDefault(): void
    {
        self::assertSame('', $this->contextWithConfig(['font_family' => 'papyrus'])->fontFamilyCss());
        self::assertSame(ThemeFontEnum::default(), $this->contextWithConfig(['font_family' => 'papyrus'])->font());
    }
}
