<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Twig;

use Aurora\Core\Twig\AppearanceExtension;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Setting\Service\EmailColors;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class AppearanceExtensionTest extends TestCase
{
    public function testReturnsConfiguredPresetsWhenStorageHasValidPayload(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->method('get')->willReturn(json_encode(['#abcdef', '#123456']));

        $extension = new AppearanceExtension($repository, new EmailColors($repository));

        self::assertSame(['#abcdef', '#123456'], $extension->getColorPickerPresets());
    }

    public function testFallsBackToDefaultsWhenStorageIsBlank(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->method('get')->willReturn('');

        $extension = new AppearanceExtension($repository, new EmailColors($repository));

        self::assertSame(
            ApplicationParameterEnum::DEFAULT_COLOR_PICKER_PRESETS,
            $extension->getColorPickerPresets(),
        );
    }

    public function testFallsBackToDefaultsWhenStorageIsMalformedJson(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->method('get')->willReturn('{not-json');

        $extension = new AppearanceExtension($repository, new EmailColors($repository));

        self::assertSame(
            ApplicationParameterEnum::DEFAULT_COLOR_PICKER_PRESETS,
            $extension->getColorPickerPresets(),
        );
    }

    public function testSkipsInvalidHexEntriesAndKeepsValidOnes(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->method('get')->willReturn(json_encode(['#ff0000', 'red', '#abc', '#00FF00']));

        $extension = new AppearanceExtension($repository, new EmailColors($repository));

        self::assertSame(['#ff0000', '#00FF00'], $extension->getColorPickerPresets());
    }

    public function testCachesResultAcrossMultipleCalls(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->expects(self::once())
            ->method('get')
            ->willReturn(json_encode(['#aabbcc']));

        $extension = new AppearanceExtension($repository, new EmailColors($repository));
        $extension->getColorPickerPresets();
        $extension->getColorPickerPresets();
    }

    public function testAResetReadsTheSettingsAgain(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $repository->expects(self::exactly(2))
            ->method('get')
            ->willReturn(json_encode(['#aabbcc']));

        $extension = new AppearanceExtension($repository, new EmailColors($repository));
        $extension->getColorPickerPresets();
        $extension->reset();
        $extension->getColorPickerPresets();
    }
}
