<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\Service;

use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Configuration\Theme\Service\PrimaryColorPalette;
use Aurora\Module\Configuration\Theme\Service\SurfaceContrast;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Logo seul sur téléphone": the site name only goes away if it was asked
 * for, and only when a logo remains to replace it.
 */
#[AllowMockObjectsWithoutExpectations]
final class ThemeContextHeaderTextTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function contextWithConfig(array $config, bool $logoExists = true): ThemeContext
    {
        $theme = $this->createMock(ThemeInterface::class);
        $theme->method('getConfig')->willReturn($config);

        $repository = $this->createMock(ThemeRepository::class);
        $repository->method('findActive')->willReturn($theme);

        $document = $this->createMock(DocumentInterface::class);
        $document->method('getFilePath')->willReturn('2026/10/logo.svg');
        $document->method('getStatus')->willReturn(DocumentStatusEnum::Published);

        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->willReturn($logoExists ? $document : null);

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/uploads/2026/10/logo.svg');

        return new ThemeContext(
            $repository,
            $documents,
            new PrimaryColorPalette(),
            new SurfaceContrast(),
            new DocumentUrlGenerator($urls),
        );
    }

    public function testTheNameStaysByDefault(): void
    {
        self::assertFalse($this->contextWithConfig(['header_logo_media_id' => '12'])->headerTextHiddenOnPhone());
    }

    public function testTheNameLeavesWhenAskedAndALogoIsThere(): void
    {
        $context = $this->contextWithConfig(['header_logo_media_id' => '12', 'header_text_on_phone' => 'hidden']);

        self::assertTrue($context->headerTextHiddenOnPhone());
    }

    public function testTheNameStaysWhenThereIsNoLogoToShowInstead(): void
    {
        self::assertFalse($this->contextWithConfig(['header_text_on_phone' => 'hidden'])->headerTextHiddenOnPhone());
        self::assertFalse(
            $this->contextWithConfig(['header_logo_media_id' => '12', 'header_text_on_phone' => 'hidden'], logoExists: false)
                ->headerTextHiddenOnPhone(),
        );
    }
}
