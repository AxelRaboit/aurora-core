<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Module\Ged\Document\Contract\DocumentUsageProviderInterface;

/**
 * Aggregates usages of a GED Document across all modules, grouped by source
 * type so the UI can render readable sections.
 *
 * Module providers are pulled in via the `aurora.document_usage_provider` tag;
 * adding a new module that references Document just means implementing the
 * interface - no changes here.
 */
final readonly class DocumentUsageService
{
    /** @param iterable<DocumentUsageProviderInterface> $providers */
    public function __construct(private iterable $providers) {}

    /**
     * @return array{total: int, groups: list<array{type: string, items: list<array<string, mixed>>}>}
     */
    public function findUsages(int $documentId): array
    {
        $byType = [];
        $total = 0;
        foreach ($this->providers as $provider) {
            foreach ($provider->findUsages($documentId) as $usage) {
                $type = $usage['type'];
                $byType[$type] ??= [];
                $byType[$type][] = $usage;
                ++$total;
            }
        }

        $groups = [];
        foreach ($byType as $type => $items) {
            $groups[] = ['type' => $type, 'items' => $items];
        }

        return ['total' => $total, 'groups' => $groups];
    }
}
