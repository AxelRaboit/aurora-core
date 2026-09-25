<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Module\Ged\Document\Contract\BatchDocumentUsageProviderInterface;
use Aurora\Module\Ged\Document\Contract\DocumentUsageProviderInterface;

use function array_fill_keys;
use function count;

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

    /**
     * How many sources point at each of these documents, all modules summed.
     *
     * What the library's listing needs to draw a badge per row. Asking
     * {@see self::findUsages()} once per row would cost a lookup per document
     * per module, and the providers that walk their source would walk it once
     * per row; a provider that declares
     * {@see BatchDocumentUsageProviderInterface} is asked once for the whole
     * page instead.
     *
     * A provider that does not declare it is still called in a loop, so a
     * module outside this bundle keeps working. That fallback is the expensive
     * path, and it is the provider's to remove.
     *
     * Ids nothing draws come back as zero rather than missing, because the
     * caller is drawing a row for each of them and an absent key reads as
     * "not answered" at the template.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countUsagesFor(array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }

        $counts = array_fill_keys($documentIds, 0);

        foreach ($this->providers as $provider) {
            if ($provider instanceof BatchDocumentUsageProviderInterface) {
                foreach ($provider->countUsagesFor($documentIds) as $documentId => $count) {
                    $counts[$documentId] = ($counts[$documentId] ?? 0) + $count;
                }

                continue;
            }

            foreach ($documentIds as $documentId) {
                $counts[$documentId] += count($provider->findUsages($documentId));
            }
        }

        return $counts;
    }
}
