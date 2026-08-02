<?php

declare(strict_types=1);

namespace Aurora\Core\Support;

/**
 * Normalises a drag-and-drop payload — `[{id, parentId, position}, …]` — into
 * the shape a Manager's reorder method expects.
 *
 * Entries without a usable id are dropped rather than rejected: a stale row
 * in the browser should not lose the rest of the reorder.
 *
 * Shared by every nested list in the backend — taxonomy terms, menu entries —
 * because the payload is the same shape wherever a tree is dragged, and one
 * copy per screen would be one place per screen for it to drift.
 */
final class TreeReorderParser
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
