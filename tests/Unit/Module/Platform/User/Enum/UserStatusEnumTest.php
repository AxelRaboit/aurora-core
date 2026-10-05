<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Platform\User\Enum;

use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use PHPUnit\Framework\TestCase;

final class UserStatusEnumTest extends TestCase
{
    public function testCaseValues(): void
    {
        self::assertSame('active', UserStatusEnum::Active->value);
        self::assertSame('invited', UserStatusEnum::Invited->value);
        self::assertSame('disabled', UserStatusEnum::Disabled->value);
        self::assertSame('pending_verification', UserStatusEnum::PendingVerification->value);
    }

    public function testGetLabelKeyPrefixesCaseValue(): void
    {
        self::assertSame('suite.users.status.active', UserStatusEnum::Active->getLabelKey());
        self::assertSame('suite.users.status.invited', UserStatusEnum::Invited->getLabelKey());
        self::assertSame('suite.users.status.disabled', UserStatusEnum::Disabled->getLabelKey());
        self::assertSame('suite.users.status.pending_verification', UserStatusEnum::PendingVerification->getLabelKey());
    }
}
