<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Enum;

use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use PHPUnit\Framework\TestCase;

final class DocumentStatusEnumTest extends TestCase
{
    public function testGetLabelKeyPrefixesValue(): void
    {
        self::assertSame('suite.ged.documents.status_draft', DocumentStatusEnum::Draft->getLabelKey());
        self::assertSame('suite.ged.documents.status_published', DocumentStatusEnum::Published->getLabelKey());
        self::assertSame('suite.ged.documents.status_archived', DocumentStatusEnum::Archived->getLabelKey());
    }
}
