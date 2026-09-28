<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Document\Service;

use Aurora\Module\Ged\Document\Contract\BatchDocumentUsageProviderInterface;
use Aurora\Module\Ged\Document\Contract\DocumentUsageProviderInterface;
use Aurora\Module\Ged\Document\Contract\TypedDocumentUsageProviderInterface;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use PHPUnit\Framework\TestCase;

final class DocumentUsageServiceTest extends TestCase
{
    public function testFindUsagesReturnsTotalAndGroups(): void
    {
        $provider = $this->createStub(DocumentUsageProviderInterface::class);
        $provider->method('findUsages')->willReturn([
            ['type' => 'billing.invoice', 'label' => 'INV-1'],
            ['type' => 'billing.invoice', 'label' => 'INV-2'],
            ['type' => 'project.task', 'label' => 'Task A'],
        ]);

        $result = (new DocumentUsageService([$provider]))->findUsages(42);

        self::assertSame(3, $result['total']);
        self::assertCount(2, $result['groups']);
    }

    public function testFindUsagesGroupsByType(): void
    {
        $provider = $this->createStub(DocumentUsageProviderInterface::class);
        $provider->method('findUsages')->willReturn([
            ['type' => 'billing.invoice', 'label' => 'INV-1'],
            ['type' => 'billing.invoice', 'label' => 'INV-2'],
        ]);

        $result = (new DocumentUsageService([$provider]))->findUsages(1);

        self::assertCount(1, $result['groups']);
        self::assertSame('billing.invoice', $result['groups'][0]['type']);
        self::assertCount(2, $result['groups'][0]['items']);
    }

    public function testFindUsagesWithNoProviders(): void
    {
        $result = (new DocumentUsageService([]))->findUsages(1);

        self::assertSame(['total' => 0, 'groups' => []], $result);
    }

    public function testFindUsagesAggregatesAcrossProviders(): void
    {
        $invoiceProvider = $this->createStub(DocumentUsageProviderInterface::class);
        $invoiceProvider->method('findUsages')->willReturn([['type' => 'billing.invoice', 'label' => 'INV-1']]);

        $taskProvider = $this->createStub(DocumentUsageProviderInterface::class);
        $taskProvider->method('findUsages')->willReturn([['type' => 'project.task', 'label' => 'Task A']]);

        $result = (new DocumentUsageService([$invoiceProvider, $taskProvider]))->findUsages(1);

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['groups']);
    }

    public function testBatchCountsAreFiledUnderTheTypeTheProviderDeclares(): void
    {
        $posts = $this->typedBatch('editorial.post', [1 => 2]);
        $decks = $this->typedBatch('studio.deck', [1 => 1, 2 => 1]);

        $service = new DocumentUsageService([$posts, $decks]);

        self::assertSame(
            [1 => ['editorial.post' => 2, 'studio.deck' => 1], 2 => ['studio.deck' => 1], 3 => []],
            $service->countUsagesByTypeFor([1, 2, 3]),
        );
        self::assertSame([1 => 3, 2 => 1, 3 => 0], $service->countUsagesFor([1, 2, 3]), 'the total is the same answer summed');
    }

    public function testABatchProviderThatNamesNoTypeIsCountedAsOther(): void
    {
        $provider = $this->createStub(BatchDocumentUsageProviderInterface::class);
        $provider->method('countUsagesFor')->willReturn([7 => 1]);

        self::assertSame([7 => [DocumentUsageService::OTHER_TYPE => 1]], (new DocumentUsageService([$provider]))->countUsagesByTypeFor([7]));
    }

    public function testTheOneByOneFallbackReadsTheTypeOffEachItem(): void
    {
        $provider = $this->createStub(DocumentUsageProviderInterface::class);
        $provider->method('findUsages')->willReturn([
            ['type' => 'billing.invoice', 'label' => 'INV-1'],
            ['type' => 'billing.invoice', 'label' => 'INV-2'],
        ]);

        self::assertSame([5 => ['billing.invoice' => 2]], (new DocumentUsageService([$provider]))->countUsagesByTypeFor([5]));
    }

    /** @param array<int, int> $counts */
    private function typedBatch(string $type, array $counts): BatchDocumentUsageProviderInterface
    {
        return new readonly class($type, $counts) implements BatchDocumentUsageProviderInterface, TypedDocumentUsageProviderInterface {
            /** @param array<int, int> $counts */
            public function __construct(private string $type, private array $counts) {}

            public function usageType(): string
            {
                return $this->type;
            }

            public function findUsages(int $documentId): array
            {
                return [];
            }

            public function countUsagesFor(array $documentIds): array
            {
                return $this->counts;
            }
        };
    }
}
