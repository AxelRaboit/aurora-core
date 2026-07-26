<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Setting\Enum;

use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use PHPUnit\Framework\TestCase;

final class ModuleParameterEnumTest extends TestCase
{
    public function testGetKeyReturnsStringValue(): void
    {
        self::assertSame('modules_ged_backend', ModuleParameterEnum::GedBackend->getKey());
        self::assertSame('modules_platform_backend', ModuleParameterEnum::PlatformBackend->getKey());
        self::assertSame('modules_ged_documents', ModuleParameterEnum::GedDocuments->getKey());
    }

    public function testGetLabelReturnsTranslationKey(): void
    {
        self::assertSame('backend.modules.ged_backend', ModuleParameterEnum::GedBackend->getLabel());
        self::assertSame('backend.modules.platform_backend', ModuleParameterEnum::PlatformBackend->getLabel());
        self::assertSame('backend.nav.documents', ModuleParameterEnum::GedDocuments->getLabel());
        self::assertSame('backend.nav.users', ModuleParameterEnum::PlatformUsers->getLabel());
    }

    public function testGetDescriptionReturnsTranslationKey(): void
    {
        self::assertSame('backend.modules.ged_backend_description', ModuleParameterEnum::GedBackend->getDescription());
        self::assertSame('backend.modules.platform_backend_description', ModuleParameterEnum::PlatformBackend->getDescription());
        self::assertSame('backend.nav.documents_description', ModuleParameterEnum::GedDocuments->getDescription());
    }

    public function testGetDefaultValueIsOneForAllCases(): void
    {
        foreach (ModuleParameterEnum::cases() as $case) {
            self::assertSame('1', $case->getDefaultValue(), sprintf('%s should default to "1"', $case->name));
        }
    }

    public function testGetTypeIsBoolForAllCases(): void
    {
        foreach (ModuleParameterEnum::cases() as $case) {
            self::assertSame('bool', $case->getType(), sprintf('%s should have type "bool"', $case->name));
        }
    }

    public function testGetGroupReturnsModuleConstantForAllCases(): void
    {
        foreach (ModuleParameterEnum::cases() as $case) {
            self::assertSame(ModuleParameterEnum::MODULE, $case->getGroup(), sprintf('%s should be in MODULE group', $case->name));
        }
    }

    public function testGetCascadeRequiresSubModuleDependencies(): void
    {
        self::assertSame(ModuleParameterEnum::GedBackend->value, ModuleParameterEnum::GedDocuments->getCascadeRequires());
        self::assertSame(ModuleParameterEnum::GedBackend->value, ModuleParameterEnum::GedFrontend->getCascadeRequires());
        self::assertSame(ModuleParameterEnum::PlatformBackend->value, ModuleParameterEnum::PlatformUsers->getCascadeRequires());
        self::assertSame(ModuleParameterEnum::ConfigurationBackend->value, ModuleParameterEnum::ConfigurationThemes->getCascadeRequires());
    }

    public function testGetCascadeRequiresNullForTopLevelWithoutDependency(): void
    {
        self::assertNull(ModuleParameterEnum::GeneralBackend->getCascadeRequires());
        self::assertNull(ModuleParameterEnum::PlatformBackend->getCascadeRequires());
        self::assertNull(ModuleParameterEnum::ConfigurationBackend->getCascadeRequires());
        self::assertNull(ModuleParameterEnum::MediaBackend->getCascadeRequires());
        self::assertNull(ModuleParameterEnum::GedBackend->getCascadeRequires());
    }

    public function testGetCascadeDisableTargetsGedEnabled(): void
    {
        $targets = ModuleParameterEnum::GedBackend->getCascadeDisableTargets();

        self::assertContains(ModuleParameterEnum::GedDocuments->value, $targets);
        self::assertContains(ModuleParameterEnum::GedCategories->value, $targets);
        self::assertContains(ModuleParameterEnum::GedTags->value, $targets);
        self::assertContains(ModuleParameterEnum::GedFolders->value, $targets);
        self::assertContains(ModuleParameterEnum::GedFrontend->value, $targets);
    }

    public function testGetCascadeDisableTargetsPlatformEnabled(): void
    {
        $targets = ModuleParameterEnum::PlatformBackend->getCascadeDisableTargets();

        self::assertContains(ModuleParameterEnum::PlatformUsers->value, $targets);
    }

    public function testGetParentCaseForTopLevelReturnsNull(): void
    {
        self::assertNull(ModuleParameterEnum::GeneralBackend->getParentCase());
        self::assertNull(ModuleParameterEnum::PlatformBackend->getParentCase());
        self::assertNull(ModuleParameterEnum::ConfigurationBackend->getParentCase());
        self::assertNull(ModuleParameterEnum::GedBackend->getParentCase());
    }

    public function testGetParentCaseForSubModules(): void
    {
        self::assertSame(ModuleParameterEnum::GedBackend, ModuleParameterEnum::GedDocuments->getParentCase());
        self::assertSame(ModuleParameterEnum::GedBackend, ModuleParameterEnum::GedFrontend->getParentCase());
        self::assertSame(ModuleParameterEnum::PlatformBackend, ModuleParameterEnum::PlatformUsers->getParentCase());
        self::assertSame(ModuleParameterEnum::ConfigurationBackend, ModuleParameterEnum::ConfigurationThemes->getParentCase());
        self::assertSame(ModuleParameterEnum::MediaBackend, ModuleParameterEnum::MediaLibrary->getParentCase());
    }

    public function testGetModuleIdForTopLevelEnabledCases(): void
    {
        self::assertSame('general', ModuleParameterEnum::GeneralBackend->getModuleId());
        self::assertSame('platform', ModuleParameterEnum::PlatformBackend->getModuleId());
        self::assertSame('configuration', ModuleParameterEnum::ConfigurationBackend->getModuleId());
        self::assertSame('media', ModuleParameterEnum::MediaBackend->getModuleId());
        self::assertSame('ged', ModuleParameterEnum::GedBackend->getModuleId());
    }

    public function testGetModuleIdReturnsNullForSubModules(): void
    {
        self::assertNull(ModuleParameterEnum::GedDocuments->getModuleId());
        self::assertNull(ModuleParameterEnum::GedFrontend->getModuleId());
        self::assertNull(ModuleParameterEnum::PlatformUsers->getModuleId());
        self::assertNull(ModuleParameterEnum::ConfigurationThemes->getModuleId());
        self::assertNull(ModuleParameterEnum::MediaLibrary->getModuleId());
        self::assertNull(ModuleParameterEnum::GeneralDashboard->getModuleId());
    }
}
