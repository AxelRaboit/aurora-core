<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Trash;

use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The reader's own notes, and nobody else's.
 *
 * The other sources count what the installation holds; this one counts what
 * the person looking at the screen deleted. Without a user in the session
 * there is nothing to count, and the row is shown empty rather than hidden, so
 * the page does not change shape depending on how it was reached.
 */
final readonly class NotesTrashSource implements TrashSourceInterface
{
    public function __construct(
        private MarkdownNoteRepository $noteRepository,
        private Security $security,
    ) {}

    public function getModuleKey(): string
    {
        return 'notes';
    }

    public function getRequiredPrivilege(): string
    {
        return 'notes.markdown.use';
    }

    public function getSummary(int $limit): TrashSummary
    {
        $user = $this->security->getUser();
        $roots = $user instanceof CoreUserInterface
            ? $this->noteRepository->findTrashedRootsForUser($user)
            : [];

        return new TrashSummary(
            key: 'notes_markdown',
            labelKey: 'backend.nav.notes_markdown',
            icon: 'notebook-pen',
            count: count($roots),
            items: array_map($this->present(...), array_slice($roots, 0, $limit)),
            oldestDeletedAt: $user instanceof CoreUserInterface
                ? $this->noteRepository->oldestTrashedAtForUser($user)
                : null,
            restoreRoute: 'backend_notes_markdown_restore',
            forceDeleteRoute: 'backend_notes_markdown_force_delete',
            emptyTrashRoute: 'backend_notes_markdown_empty_trash',
            actionPrivilege: 'notes.markdown.use',
        );
    }

    private function present(MarkdownNoteInterface $note): TrashItem
    {
        $title = $note->getTitle();

        return new TrashItem(
            id: (int) $note->getId(),
            label: null !== $title && '' !== $title ? $title : '#'.$note->getId(),
            deletedAt: $note->getDeletedAt(),
        );
    }
}
