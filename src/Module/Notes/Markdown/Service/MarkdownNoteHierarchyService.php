<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;

/**
 * Stateless helpers for the note tree (parent/child relations).
 *
 * Kept out of the Manager because these are pure read-only computations
 * with no persistence - and out of the Controller because they're domain
 * logic that should be unit-testable and reusable (move endpoint, future
 * drag-drop validation, import sanity checks…).
 */
final readonly class MarkdownNoteHierarchyService
{
    /**
     * True if moving $note under $newParent would create a cycle, i.e.
     * $newParent is $note itself or a descendant of $note. Walks the
     * ancestor chain of $newParent.
     */
    public function wouldCreateCycle(MarkdownNoteInterface $note, MarkdownNoteInterface $newParent): bool
    {
        $current = $newParent;
        while ($current instanceof MarkdownNoteInterface) {
            if ($current->getId() === $note->getId()) {
                return true;
            }

            $current = $current->getParent();
        }

        return false;
    }
}
