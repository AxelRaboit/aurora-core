<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Live\Service;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteShareLinkRepository;
use DateTimeImmutable;

/**
 * Whether a note is written together, letter by letter, by whoever has it open.
 *
 * **Two ways in, and either is enough.** A space whose settings switch
 * co-editing on, which is the colleague case and never a personal space. Or a
 * usable writing link whose own "live co-editing" box is ticked, which is the
 * Google Docs case - "anyone with the link can edit" - and holds for a
 * personal note too: ticking it is the owner's say-so that the note is written
 * with whoever holds the address, and a guest typing alone in a room the
 * owner cannot join would be a session in name only. Off by default on every
 * link. Decided with Axel on 09/10/2026.
 *
 * **One answer for both sides of the link.** The back office asks it through
 * the beat, the guest page through its own beat; asking it twice in two places
 * would be how the owner and the guest end up in different rooms.
 */
final readonly class NoteCoediting
{
    public function __construct(
        private MarkdownNoteShareLinkRepository $shareLinkRepository,
        private NotesContext $notesContext,
    ) {}

    public function isCoeditable(MarkdownNoteInterface $note, ?DateTimeImmutable $now = null): bool
    {
        if ($note->getSpace()->allowsCoediting()) {
            return true;
        }

        return $this->hasWritingLink($note, $now ?? new DateTimeImmutable());
    }

    /**
     * A link that may write this note right now, and opens it to the room.
     *
     * Through the same switch the guest route answers to: with link writing
     * turned off for the installation, no link writes, so none opens a room.
     */
    private function hasWritingLink(MarkdownNoteInterface $note, DateTimeImmutable $now): bool
    {
        if (!$this->notesContext->isCollaborationEnabled()) {
            return false;
        }

        foreach ($this->shareLinkRepository->findForNote($note) as $link) {
            if ($link instanceof MarkdownNoteShareLinkInterface && $link->allowsCoediting() && $link->canWriteNote($note, $now)) {
                return true;
            }
        }

        return false;
    }
}
