<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Platform\User\Enum;

use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use PHPUnit\Framework\TestCase;

final class UserTypeEnumTest extends TestCase
{
    public function testGetLabelKeyPrefixesValue(): void
    {
        self::assertSame('suite.users.type.suite', UserTypeEnum::Suite->getLabelKey());
        self::assertSame('suite.users.type.frontend', UserTypeEnum::Frontend->getLabelKey());
    }
}
