<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Service;

use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Tests\Integration\IntegrationTestCase;

final class DocumentUsageProvidersTest extends IntegrationTestCase
{
    public function testEveryProviderQueryRunsAgainstTheRealSchema(): void
    {
        static::createClient();
        $service = static::getContainer()->get(DocumentUsageService::class);

        // No document carries this id; the value of the test is that all
        // tagged providers' query builders execute against the real schema
        // without error - guarding the Document FK relation/field names from
        // drift (same class of bug that broke the Media /usage endpoint).
        $result = $service->findUsages(999999999);

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['groups']);
    }
}
