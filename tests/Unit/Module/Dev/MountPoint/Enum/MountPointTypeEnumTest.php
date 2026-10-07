<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Dev\MountPoint\Enum;

use Aurora\Module\Dev\MountPoint\Enum\MountPointTypeEnum;
use PHPUnit\Framework\TestCase;

final class MountPointTypeEnumTest extends TestCase
{
    public function testEachTypeHasATranslationKey(): void
    {
        self::assertSame('suite.mount_points.types.database', MountPointTypeEnum::Database->getLabelKey());
        self::assertSame('suite.mount_points.types.api', MountPointTypeEnum::Api->getLabelKey());
        self::assertSame('suite.mount_points.types.sftp', MountPointTypeEnum::Sftp->getLabelKey());
    }

    public function testCases(): void
    {
        self::assertSame('database', MountPointTypeEnum::Database->value);
        self::assertSame('api', MountPointTypeEnum::Api->value);
        self::assertSame('sftp', MountPointTypeEnum::Sftp->value);
    }
}
