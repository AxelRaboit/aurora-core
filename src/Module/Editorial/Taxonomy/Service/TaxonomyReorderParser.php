<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Service;

use Aurora\Module\Editorial\Taxonomy\Manager\TaxonomyManagerInterface;

/**
 * Normalises the drag-and-drop payload into what
 * {@see TaxonomyManagerInterface::reorderTerms()}
 * expects. Entries without a usable id are dropped rather than rejected:
 * a stale row in the browser should not lose the rest of the reorder.
 */
final class TaxonomyReorderParser
{
    /**
     * @return list<array{id: int, parentId: ?int, position: int}>
     */
    public static function parse(mixed $rawEntries): array
    {
        if (!is_array($rawEntries)) {
            return [];
        }

        $entries = [];
        foreach ($rawEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = (int) ($entry['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $parentId = (int) ($entry['parentId'] ?? 0);

            $entries[] = [
                'id' => $id,
                'parentId' => $parentId > 0 ? $parentId : null,
                'position' => (int) ($entry['position'] ?? 0),
            ];
        }

        return $entries;
    }
}
