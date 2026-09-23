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
 * One kind of recursion is left, and it is the unbounded one: **`[[wiki
 * links]]`**, followed transitively, where a note citing two notes that each
 * cite two more reaches most of a vault in three hops. This is why the share
 * screen lists the titles it would publish instead of counting them, a number
 * being impossible to check against what somebody meant to share.
 *
 * The other one, the tree, is gone with the tree itself: notes are filed in
 * folders now, and a folder is not shared. Sharing a folder would publish a
 * set that grows every time a note is added to it, long after the link was
 * sent, which is not something a switch on a screen can make safe.
 *
 * The switch is off by default, and a note it does not reach is never in
 * scope, whatever id is asked for.
 */
final readonly class SharedNoteScope
{
    public function __construct(
        private MarkdownNoteRepository $notes,
        private WikiLinkParser $wikiLinks,
    ) {}

    /**
     * The notes a link exposes, root first.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function notesFor(MarkdownNoteShareLinkInterface $link): array
    {
        return $this->walk($link->getNote(), $link->includesLinked());
    }

    /**
     * What a share with this switch would carry, root first.
     *
     * Used both to resolve a live link and to answer the share screen before
     * anything is created: the list of titles somebody is about to publish has
     * to come from the same walk that will serve them, or the preview is a
     * second implementation free to disagree with reality.
     *
     * @return list<MarkdownNoteInterface>
     */
    public function walk(MarkdownNoteInterface $root, bool $linked): array
    {
        if (!$linked) {
            return [$root];
        }

        // The owner's notes are loaded once and walked in memory rather than
        // queried per hop: a query per level is a round trip per level, and one
        // person's notes fit in memory by a wide margin. The bodies are needed
        // anyway, to read the links out of them.
        $all = $this->notes->findAllWithContentForUser($root->getUser());

        $byTitle = [];
        foreach ($all as $note) {
            $title = mb_trim((string) $note->getTitle());
            if ('' !== $title) {
                // First one wins: titles are not unique, and a link naming a
                // duplicated title has to resolve to one note rather than to
                // whichever the iteration order reached last.
                $byTitle[mb_strtolower($title)] ??= $note;
            }
        }

        $scope = [$root];
        $queue = [$root];
        $seen = [(int) $root->getId() => true];

        while ([] !== $queue) {
            $current = array_shift($queue);
            $next = [];

            foreach ($this->wikiLinks->titlesIn($current->getContent()) as $title) {
                if (isset($byTitle[$title])) {
                    $next[] = $byTitle[$title];
                }
            }

            foreach ($next as $note) {
                $id = (int) $note->getId();
                // The `seen` set is what makes this terminate. Two notes linking
                // to each other is an ordinary thing to write, not a corruption,
                // so an endless walk here would be a denial of service anybody
                // could trigger by writing notes the normal way.
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $scope[] = $note;
                $queue[] = $note;
            }
        }

        return $scope;
    }

    /**
     * The notes a share would carry beyond the one being shared, as `{id, title}`.
     *
     * Titles rather than a count, because a number cannot be checked against
     * intent. Seeing "Comptes 2026" in the list is what stops a share going out
     * with it; "4 notes" is not.
     *
     * @return list<array{id: int, title: string|null}>
     */
    public function preview(MarkdownNoteInterface $note, bool $linked): array
    {
        $scope = $this->walk($note, $linked);

        // The root is what is being shared, not something a switch added.
        array_shift($scope);

        return array_map(static fn (MarkdownNoteInterface $n): array => [
            'id' => (int) $n->getId(),
            'title' => $n->getTitle(),
        ], $scope);
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
     * remembers the title, not the way it was capitalised. Titles are not
     * unique, and the first in scope order wins - the root-first order the
     * reader sees.
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

            $index[mb_strtolower($title)] ??= (int) $note->getId();
        }

        return $index;
    }
}
