<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Twig;

use Aurora\Core\Twig\AppearanceExtension;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Setting\Service\EmailColors;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Configuration\Theme\Service\PrimaryColorPalette;
use Aurora\Module\Configuration\Theme\Service\SurfaceContrast;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AllowMockObjectsWithoutExpectations]
final class AppearanceExtensionTest extends TestCase
{
    public function testReturnsConfiguredPresetsWhenStorageHasValidPayload(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->method('get')->willReturn(json_encode(['#abcdef', '#123456']));

        $extension = $this->extension($repository);

        self::assertSame(['#abcdef', '#123456'], $extension->getColorPickerPresets());
    }

    public function testFallsBackToDefaultsWhenStorageIsBlank(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->method('get')->willReturn('');

        $extension = $this->extension($repository);

        self::assertSame(
            ApplicationParameterEnum::DEFAULT_COLOR_PICKER_PRESETS,
            $extension->getColorPickerPresets(),
        );
    }

    public function testFallsBackToDefaultsWhenStorageIsMalformedJson(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->method('get')->willReturn('{not-json');

        $extension = $this->extension($repository);

        self::assertSame(
            ApplicationParameterEnum::DEFAULT_COLOR_PICKER_PRESETS,
            $extension->getColorPickerPresets(),
        );
    }

    public function testSkipsInvalidHexEntriesAndKeepsValidOnes(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->method('get')->willReturn(json_encode(['#ff0000', 'red', '#abc', '#00FF00']));

        $extension = $this->extension($repository);

        self::assertSame(['#ff0000', '#00FF00'], $extension->getColorPickerPresets());
    }

    public function testCachesResultAcrossMultipleCalls(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->expects(self::once())
            ->method('get')
            ->willReturn(json_encode(['#aabbcc']));

        $extension = $this->extension($repository);
        $extension->getColorPickerPresets();
        $extension->getColorPickerPresets();
    }

    public function testAResetReadsTheSettingsAgain(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->expects(self::exactly(2))
            ->method('get')
            ->willReturn(json_encode(['#aabbcc']));

        $extension = $this->extension($repository);
        $extension->getColorPickerPresets();
        $extension->reset();
        $extension->getColorPickerPresets();
    }

    private function extension(SettingRepository $repository): AppearanceExtension
    {
        $themes = $this->createStub(ThemeRepository::class);

        return new AppearanceExtension($repository, new EmailColors($repository, new ThemeContext(
            $themes,
            $this->createStub(DocumentRepository::class),
            new PrimaryColorPalette(),
            new SurfaceContrast(),
            new DocumentUrlGenerator($this->createStub(UrlGeneratorInterface::class)),
        )));
    }
}
