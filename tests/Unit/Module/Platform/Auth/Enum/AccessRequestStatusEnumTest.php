<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Platform\Auth\Enum;

use Aurora\Module\Platform\Auth\Enum\AccessRequestStatusEnum;
use PHPUnit\Framework\TestCase;

final class AccessRequestStatusEnumTest extends TestCase
{
    public function testGetLabelKeyPrefixesValue(): void
    {
        self::assertSame('suite.access_requests.status_pending', AccessRequestStatusEnum::Pending->getLabelKey());
        self::assertSame('suite.access_requests.status_approved', AccessRequestStatusEnum::Approved->getLabelKey());
        self::assertSame('suite.access_requests.status_rejected', AccessRequestStatusEnum::Rejected->getLabelKey());
    }
}
