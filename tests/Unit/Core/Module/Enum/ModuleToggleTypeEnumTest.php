<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Module\Enum;

use Aurora\Core\Module\Enum\ModuleToggleTypeEnum;
use PHPUnit\Framework\TestCase;

final class ModuleToggleTypeEnumTest extends TestCase
{
    public function testFromKeyDetectsFrontendSuffix(): void
    {
        self::assertSame(ModuleToggleTypeEnum::Frontend, ModuleToggleTypeEnum::fromKey('suite_editorial_frontend'));
        self::assertSame(ModuleToggleTypeEnum::Frontend, ModuleToggleTypeEnum::fromKey('suite_photo_frontend'));
    }

    public function testFromKeyDefaultsToSuite(): void
    {
        self::assertSame(ModuleToggleTypeEnum::Suite, ModuleToggleTypeEnum::fromKey('suite_editorial'));
        self::assertSame(ModuleToggleTypeEnum::Suite, ModuleToggleTypeEnum::fromKey('frontend_root'));
        self::assertSame(ModuleToggleTypeEnum::Suite, ModuleToggleTypeEnum::fromKey('anything_else'));
    }
}
