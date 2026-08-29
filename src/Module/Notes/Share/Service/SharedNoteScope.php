<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Service;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;

/**
 * What a share link exposes, and nothing else.
 *
 * Everything a guest can reach is decided here, from the link alone. No
 * identifier from the request ever widens the set: a guest asking for note 12
 * gets it only if 12 is already in the scope the link defines.
 *
 * Two kinds of recursion meet in a notes application and they are not the same
 * question:
 *
 * 1. **The tree.** Notes filed under the shared note. Included only when the
 *    link says so, because publishing one note and publishing a branch of
 *    thirty are different acts.
 * 2. **`[[wiki links]]`.** A note's body can name any other note. Those are
 *    resolved *within the scope only* - a link to an included note navigates,
 *    a link to anything else renders as plain text. A link must never widen
 *    the share on its own, or the person sharing one note would be publishing
 *    whatever that note happens to mention.
 */
final readonly class SharedNoteScope
{
    public function __construct(private MarkdownNoteRepository $notes) {}

    /**
     * The notes a link exposes, root first.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function notesFor(MarkdownNoteShareLinkInterface $link): array
    {
        $root = $link->getNote();

        if (!$link->includesDescendants()) {
            return [$root];
        }

        // The whole of the owner's tree is loaded once and walked in memory
        // rather than queried per level: a recursive query per depth is a round
        // trip per level of nesting, and one person's notes fit in memory by a
        // wide margin. The same list is what the descendant count on the share
        // screen is drawn from, so both answers come from one read.
        $all = $this->notes->findAllWithContentForUser($root->getUser());

        $byParent = [];
        foreach ($all as $note) {
            $byParent[$note->getParent()?->getId() ?? 0][] = $note;
        }

        $scope = [$root];
        $queue = [$root];
        $seen = [(int) $root->getId() => true];

        while ([] !== $queue) {
            $current = array_shift($queue);
            foreach ($byParent[(int) $current->getId()] ?? [] as $child) {
                $id = (int) $child->getId();
                // A cycle cannot be built through the UI, but a hand-edited row
                // could make one, and an infinite loop in a public route is a
                // denial of service handed to whoever holds the link.
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $scope[] = $child;
                $queue[] = $child;
            }
        }

        return $scope;
    }

    /**
     * How many notes would come along if descendants were included.
     *
     * Shown next to the checkbox so the person sees the size of what they are
     * about to publish before they publish it, rather than after.
     */
    public function descendantCount(MarkdownNoteInterface $note): int
    {
        $all = $this->notes->findAllWithContentForUser($note->getUser());

        $byParent = [];
        foreach ($all as $candidate) {
            $byParent[$candidate->getParent()?->getId() ?? 0][] = $candidate;
        }

        $count = 0;
        $queue = [$note];
        $seen = [(int) $note->getId() => true];

        while ([] !== $queue) {
            $current = array_shift($queue);
            foreach ($byParent[(int) $current->getId()] ?? [] as $child) {
                $id = (int) $child->getId();
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                ++$count;
                $queue[] = $child;
            }
        }

        return $count;
    }

    /**
     * One note from a link's scope, by id, or null.
     *
     * The lookup runs over the scope rather than the repository: a guest cannot
     * name a note the link does not already carry.
     */
    public function noteInScope(MarkdownNoteShareLinkInterface $link, int $id): ?MarkdownNoteInterface
    {
        foreach ($this->notesFor($link) as $note) {
            if ((int) $note->getId() === $id) {
                return $note;
            }
        }

        return null;
    }

    /**
     * Titles inside the scope, mapped to their ids, for resolving `[[links]]`.
     *
     * Lower-cased keys because a wiki link is written the way the writer
     * remembers the title, not the way it was capitalised. Titles are not unique
     * - two notes can share one - and the first in scope order wins, which is
     * the root-first order the reader sees.
     *
     * @return array<string, int>
     */
    public function titleIndex(MarkdownNoteShareLinkInterface $link): array
    {
        $index = [];
        foreach ($this->notesFor($link) as $note) {
            $title = mb_trim((string) $note->getTitle());
            if ('' === $title) {
                continue;
            }

            $key = mb_strtolower($title);
            $index[$key] ??= (int) $note->getId();
        }

        return $index;
    }
}
