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
use RuntimeException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The 404, 500 and 503 pages wear the site's theme rather than the
 * stylesheet's default green - and stay up when the theme cannot be read.
 */
#[AllowMockObjectsWithoutExpectations]
final class ThemeErrorPageCssTest extends TestCase
{
    public function testAnErrorPageWearsTheThemesAccentAndBackground(): void
    {
        $theme = $this->createMock(ThemeInterface::class);
        $theme->method('getConfig')->willReturn(['primary_color' => '#f97316', 'background_color' => '#1c1917']);
        $repository = $this->createMock(ThemeRepository::class);
        $repository->method('findActive')->willReturn($theme);

        $css = $this->renderer($repository)->errorPageCss();

        self::assertStringContainsString('--th-accent-500', $css);
        self::assertStringContainsString('html[data-theme]', $css);
        self::assertStringContainsString('#1c1917', $css);
    }

    /** A 500 is often the database failing: the page must still render. */
    public function testAThemeThatCannotBeReadLeavesThePageItsOwnColours(): void
    {
        $repository = $this->createMock(ThemeRepository::class);
        $repository->method('findActive')->willThrowException(new RuntimeException('database down'));

        self::assertSame('', $this->renderer($repository)->errorPageCss());
    }

    private function renderer(ThemeRepository $repository): ThemeStyleRenderer
    {
        $context = new ThemeContext(
            $repository,
            $this->createMock(DocumentRepository::class),
            new PrimaryColorPalette(),
            new SurfaceContrast(),
            new DocumentUrlGenerator($this->createMock(UrlGeneratorInterface::class)),
        );

        return new ThemeStyleRenderer($context, new PrimaryColorPalette(), new SurfaceContrast());
    }
}
