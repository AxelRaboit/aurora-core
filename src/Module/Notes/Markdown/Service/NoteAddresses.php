<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Hosting\NoteSpaceHostInterface;
use Aurora\Module\Notes\Space\Hosting\NoteSpaceHosts;
use Aurora\Module\Notes\Space\Hosting\NoteSpacePagePaths;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a note is read.
 *
 * A note of the Notes module opens in the module; a note of a hosted space
 * opens where its host shows it - a client space's note, in the client space
 * (10/10/2026). Every link to a note goes through here: a notification, a
 * search result, an old address of the module, so a hosted note never sends
 * its reader to a screen it no longer belongs to.
 */
final readonly class NoteAddresses
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private NoteSpaceHosts $hosts,
    ) {}

    public function noteUrl(MarkdownNoteInterface $note): string
    {
        return $this->noteUrlIn($note->getSpace(), (int) $note->getId());
    }

    /** The same, from a row that carries the space and the note's id rather than the note. */
    public function noteUrlIn(NoteSpaceInterface $space, int $noteId): string
    {
        return $this->hostPaths($space)?->noteUrl($noteId)
            ?? $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => $noteId]);
    }

    /** The host's page for a hosted note, null for a note of the module itself. */
    public function hostedNoteUrl(MarkdownNoteInterface $note): ?string
    {
        return $this->hostPaths($note->getSpace())?->noteUrl((int) $note->getId());
    }

    /** The name a hosted space goes by in its host, null for a space of the module. */
    public function hostLabel(NoteSpaceInterface $space): ?string
    {
        return $this->hosts->of($space)?->labelOf($space);
    }

    private function hostPaths(NoteSpaceInterface $space): ?NoteSpacePagePaths
    {
        $host = $this->hosts->of($space);

        return $host instanceof NoteSpaceHostInterface ? $host->pagePaths($space) : null;
    }
}
