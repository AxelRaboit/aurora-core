<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Manager;

use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface MarkdownNoteManagerInterface
{
    public function create(CoreUserInterface $user, MarkdownNoteInputInterface $input): MarkdownNoteInterface;

    public function update(MarkdownNoteInterface $note, MarkdownNoteInputInterface $input): void;

    public function delete(MarkdownNoteInterface $note): void;

    public function move(MarkdownNoteInterface $note, ?MarkdownNoteInterface $parent): void;

    /**
     * Reorder a whole sub-tree of notes in one shot. Each entry carries
     * the desired `parentId` and `position` for a given note id. Used by
     * the drag-drop UI which flattens the visible tree client-side.
     *
     * Detects cycles atomically on the intended state and throws
     * \InvalidArgumentException if any are found.
     *
     * @param list<array{id: int, parentId: ?int, position: int}> $entries
     */
    public function reorder(CoreUserInterface $user, array $entries): void;

    /**
     * Notes that contain a [[title]] wiki-link pointing to $note.
     * Case-insensitive title match.
     *
     * @return list<array{id: int, title: ?string}>
     */
    public function backlinks(CoreUserInterface $user, MarkdownNoteInterface $note): array;

    /**
     * Notes that mention $note's title in their content without using
     * the [[…]] wiki-link syntax. Case-insensitive substring match.
     *
     * @return list<array{id: int, title: ?string}>
     */
    public function unlinkedMentions(CoreUserInterface $user, MarkdownNoteInterface $note): array;

    /**
     * Wiki-link graph for the whole user's notes. Edges are extracted
     * from [[target]] occurrences resolved against existing titles.
     *
     * @return array{
     *     nodes: list<array{id: int, title: string}>,
     *     edges: list<array{source: int, target: int}>,
     * }
     */
    public function graph(CoreUserInterface $user): array;

    /**
     * Histogram of tag → number of the user's notes carrying it,
     * sorted alphabetically (natural, case-insensitive).
     *
     * @return array<string, int>
     */
    public function tagCounts(CoreUserInterface $user): array;

    /**
     * Full-text search the user's notes - matches against decrypted
     * `content` (case-insensitive substring). Title / tag matches are
     * handled client-side from the flat list (those fields ship in the
     * sidebar payload), so this method intentionally focuses on the
     * one field the client doesn't have. Empty or whitespace-only
     * queries return an empty list.
     *
     * @return list<int>
     */
    public function searchContent(CoreUserInterface $user, string $query): array;

    /**
     * Replace every occurrence of `$oldTag` by `$newTag` across the user's
     * notes. Dedupes when the target tag is already present on the same
     * note. Returns the number of notes mutated.
     */
    public function renameTag(CoreUserInterface $user, string $oldTag, string $newTag): int;

    /**
     * Replace every occurrence of any tag in `$sourceTags` by `$targetTag`
     * across the user's notes. Source tags equal to the target are
     * skipped. Returns the number of notes mutated.
     *
     * @param list<string> $sourceTags
     */
    public function mergeTags(CoreUserInterface $user, array $sourceTags, string $targetTag): int;

    /**
     * Strip `$tag` from every one of the user's notes. Returns the number
     * of notes mutated.
     */
    public function removeTag(CoreUserInterface $user, string $tag): int;
}
