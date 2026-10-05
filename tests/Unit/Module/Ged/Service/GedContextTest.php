<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Service;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Ged\GedContext;
use PHPUnit\Framework\TestCase;

final class GedContextTest extends TestCase
{
    /** @param array<string, bool> $values */
    private function makeContext(array $values): GedContext
    {
        $checker = $this->createStub(ModuleAccessChecker::class);
        $checker->method('isEnabled')->willReturnCallback(
            static fn (ModuleParameterEnum $module): bool => $values[$module->value] ?? true,
        );

        return new GedContext($checker);
    }

    public function testIsAdminEnabled(): void
    {
        self::assertTrue($this->makeContext([ModuleParameterEnum::GedSuite->value => true])->isSuiteEnabled());
        self::assertFalse($this->makeContext([ModuleParameterEnum::GedSuite->value => false])->isSuiteEnabled());
    }

    public function testIsDocumentsEnabled(): void
    {
        self::assertTrue($this->makeContext([ModuleParameterEnum::GedDocuments->value => true])->isDocumentsEnabled());
        self::assertFalse($this->makeContext([ModuleParameterEnum::GedDocuments->value => false])->isDocumentsEnabled());
    }

    public function testIsCategoriesEnabled(): void
    {
        self::assertTrue($this->makeContext([ModuleParameterEnum::GedCategories->value => true])->isCategoriesEnabled());
        self::assertFalse($this->makeContext([ModuleParameterEnum::GedCategories->value => false])->isCategoriesEnabled());
    }
}
