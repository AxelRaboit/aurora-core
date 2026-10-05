<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Setting\Enum;

use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use PHPUnit\Framework\TestCase;

final class ModuleParameterEnumTest extends TestCase
{
    public function testGetKeyReturnsStringValue(): void
    {
        self::assertSame('modules_ged_suite', ModuleParameterEnum::GedSuite->getKey());
        self::assertSame('modules_platform_suite', ModuleParameterEnum::PlatformSuite->getKey());
        self::assertSame('modules_ged_documents', ModuleParameterEnum::GedDocuments->getKey());
    }

    public function testGetLabelReturnsTranslationKey(): void
    {
        self::assertSame('suite.modules.ged_suite', ModuleParameterEnum::GedSuite->getLabel());
        self::assertSame('suite.modules.platform_suite', ModuleParameterEnum::PlatformSuite->getLabel());
        self::assertSame('suite.nav.documents', ModuleParameterEnum::GedDocuments->getLabel());
        self::assertSame('suite.nav.users', ModuleParameterEnum::PlatformUsers->getLabel());
    }

    public function testGetDescriptionReturnsTranslationKey(): void
    {
        self::assertSame('suite.modules.ged_suite_description', ModuleParameterEnum::GedSuite->getDescription());
        self::assertSame('suite.modules.platform_suite_description', ModuleParameterEnum::PlatformSuite->getDescription());
        self::assertSame('suite.nav.documents_description', ModuleParameterEnum::GedDocuments->getDescription());
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
        self::assertSame(ModuleParameterEnum::GedSuite->value, ModuleParameterEnum::GedDocuments->getCascadeRequires());
        self::assertSame(ModuleParameterEnum::GedSuite->value, ModuleParameterEnum::GedFrontend->getCascadeRequires());
        self::assertSame(ModuleParameterEnum::PlatformSuite->value, ModuleParameterEnum::PlatformUsers->getCascadeRequires());
        self::assertSame(ModuleParameterEnum::ConfigurationSuite->value, ModuleParameterEnum::ConfigurationThemes->getCascadeRequires());
    }

    public function testGetCascadeRequiresNullForTopLevelWithoutDependency(): void
    {
        self::assertNull(ModuleParameterEnum::GeneralSuite->getCascadeRequires());
        self::assertNull(ModuleParameterEnum::PlatformSuite->getCascadeRequires());
        self::assertNull(ModuleParameterEnum::ConfigurationSuite->getCascadeRequires());
        self::assertNull(ModuleParameterEnum::GedSuite->getCascadeRequires());
    }

    public function testGetCascadeDisableTargetsGedEnabled(): void
    {
        $targets = ModuleParameterEnum::GedSuite->getCascadeDisableTargets();

        self::assertContains(ModuleParameterEnum::GedDocuments->value, $targets);
        self::assertContains(ModuleParameterEnum::GedCategories->value, $targets);
        self::assertContains(ModuleParameterEnum::GedTags->value, $targets);
        self::assertContains(ModuleParameterEnum::GedFolders->value, $targets);
        self::assertContains(ModuleParameterEnum::GedFrontend->value, $targets);
    }

    public function testGetCascadeDisableTargetsPlatformEnabled(): void
    {
        $targets = ModuleParameterEnum::PlatformSuite->getCascadeDisableTargets();

        self::assertContains(ModuleParameterEnum::PlatformUsers->value, $targets);
    }

    public function testGetParentCaseForTopLevelReturnsNull(): void
    {
        self::assertNull(ModuleParameterEnum::GeneralSuite->getParentCase());
        self::assertNull(ModuleParameterEnum::PlatformSuite->getParentCase());
        self::assertNull(ModuleParameterEnum::ConfigurationSuite->getParentCase());
        self::assertNull(ModuleParameterEnum::GedSuite->getParentCase());
    }

    public function testGetParentCaseForSubModules(): void
    {
        self::assertSame(ModuleParameterEnum::GedSuite, ModuleParameterEnum::GedDocuments->getParentCase());
        self::assertSame(ModuleParameterEnum::GedSuite, ModuleParameterEnum::GedFrontend->getParentCase());
        self::assertSame(ModuleParameterEnum::PlatformSuite, ModuleParameterEnum::PlatformUsers->getParentCase());
        self::assertSame(ModuleParameterEnum::ConfigurationSuite, ModuleParameterEnum::ConfigurationThemes->getParentCase());
    }

    public function testGetModuleIdForTopLevelEnabledCases(): void
    {
        self::assertSame('general', ModuleParameterEnum::GeneralSuite->getModuleId());
        self::assertSame('platform', ModuleParameterEnum::PlatformSuite->getModuleId());
        self::assertSame('configuration', ModuleParameterEnum::ConfigurationSuite->getModuleId());
        self::assertSame('ged', ModuleParameterEnum::GedSuite->getModuleId());
    }

    public function testGetModuleIdReturnsNullForSubModules(): void
    {
        self::assertNull(ModuleParameterEnum::GedDocuments->getModuleId());
        self::assertNull(ModuleParameterEnum::GedFrontend->getModuleId());
        self::assertNull(ModuleParameterEnum::PlatformUsers->getModuleId());
        self::assertNull(ModuleParameterEnum::ConfigurationThemes->getModuleId());
        self::assertNull(ModuleParameterEnum::GeneralDashboard->getModuleId());
    }
}
