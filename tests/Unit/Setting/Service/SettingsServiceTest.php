<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Setting\Service;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Toggle\ModuleToggleRegistry;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Exception\CascadeViolationException;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Setting\Service\SettingsService;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SettingsServiceTest extends TestCase
{
    private function makeService(SettingRepository $repository, AuditLogger $auditLogger): SettingsService
    {
        // Build a real registry from a fake module exposing every current
        // ModuleParameterEnum toggle - the cascade graph the service relies on.
        $module = new class implements ModuleInterface, ModuleToggleProviderInterface {
            public function getId(): string
            {
                return 'test';
            }

            public function getNavSections(): array
            {
                return [];
            }

            public function getCatalogNavSections(): array
            {
                return [];
            }

            public function getPermissions(): array
            {
                return [];
            }

            public function getToggles(): array
            {
                return array_map(static fn (ModuleParameterEnum $case) => $case->toToggle(), ModuleParameterEnum::cases());
            }
        };

        return new SettingsService($repository, $auditLogger, new ModuleToggleRegistry([$module]));
    }

    public function testSetModuleParameterWithParentActiveDoesNotThrow(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $auditLogger = $this->createMock(AuditLogger::class);

        $repository->method('get')->willReturn('1');
        $repository->expects(self::once())->method('saveMany');

        $service = $this->makeService($repository, $auditLogger);
        $service->set(ModuleParameterEnum::GedDocuments->value, '1');
    }

    public function testSetModuleParameterWithParentDisabledThrowsCascadeViolation(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $auditLogger = $this->createMock(AuditLogger::class);

        $repository->method('get')->willReturn('0');

        $service = $this->makeService($repository, $auditLogger);

        $this->expectException(CascadeViolationException::class);
        $service->set(ModuleParameterEnum::GedDocuments->value, '1');
    }

    public function testSetModuleParameterToZeroCascadesChildren(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $auditLogger = $this->createMock(AuditLogger::class);

        $capturedWrites = null;
        $repository->expects(self::once())
            ->method('saveMany')
            ->with(self::callback(function (array $writes) use (&$capturedWrites): bool {
                $capturedWrites = $writes;

                return true;
            }));

        $service = $this->makeService($repository, $auditLogger);
        $service->set(ModuleParameterEnum::GedBackend->value, '0');

        $keys = array_column($capturedWrites, 0);
        self::assertContains(ModuleParameterEnum::GedBackend->value, $keys);
        self::assertContains(ModuleParameterEnum::GedDocuments->value, $keys);
        self::assertContains(ModuleParameterEnum::GedCategories->value, $keys);
        self::assertContains(ModuleParameterEnum::GedTags->value, $keys);
        self::assertContains(ModuleParameterEnum::GedFolders->value, $keys);
        self::assertContains(ModuleParameterEnum::GedFrontend->value, $keys);

        foreach ($capturedWrites as [$key, $value]) {
            self::assertSame('0', $value);
        }
    }

    public function testSetApplicationParameterDoesNotCascade(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $auditLogger = $this->createMock(AuditLogger::class);

        $repository->expects(self::once())
            ->method('saveMany')
            ->with([[$_key = ApplicationParameterEnum::SiteName->value, 'My Site']]);

        $service = $this->makeService($repository, $auditLogger);
        $service->set(ApplicationParameterEnum::SiteName->value, 'My Site');
    }

    public function testSetCallsAuditLoggerAfterPersistence(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $auditLogger = $this->createMock(AuditLogger::class);

        $repository->method('get')->willReturn('1');

        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                'core',
                'settings.updated',
                null,
                null,
                ['key' => ModuleParameterEnum::GedBackend->value, 'value' => '1'],
            );

        $service = $this->makeService($repository, $auditLogger);
        $service->set(ModuleParameterEnum::GedBackend->value, '1');
    }

    public function testSetUnknownKeyPersistsWithoutCascade(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $auditLogger = $this->createMock(AuditLogger::class);

        $repository->expects(self::once())
            ->method('saveMany')
            ->with([['unknown_custom_key', 'some_value']]);

        $service = $this->makeService($repository, $auditLogger);
        $service->set('unknown_custom_key', 'some_value');
    }
}
