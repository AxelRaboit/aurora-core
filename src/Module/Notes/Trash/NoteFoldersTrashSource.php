<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Trash;

use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\SecurityBundle\Security;

use function count;

/**
 * The reader's own folders, and nobody else's.
 *
 * Deleted on their own only: a sub-folder that fell with its parent comes
 * back with it, and a note that fell with a folder is restored by restoring
 * that folder, not by an entry of its own in this list.
 *
 * Like {@see NotesTrashSource}, the count is the count of the person looking
 * at the screen, not the installation's.
 */
final readonly class NoteFoldersTrashSource implements TrashSourceInterface
{
    public function __construct(
        private NoteFolderRepository $folderRepository,
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
            ? $this->folderRepository->findTrashedRootsForUser($user)
            : [];

        return new TrashSummary(
            key: 'notes_markdown_folders',
            labelKey: 'notes.markdown.folders.trash_label',
            sectionId: 'notes',
            icon: 'folder',
            count: count($roots),
            items: array_map($this->present(...), array_slice($roots, 0, $limit)),
            oldestDeletedAt: $user instanceof CoreUserInterface
                ? $this->folderRepository->oldestTrashedAtForUser($user)
                : null,
            restoreRoute: 'backend_notes_markdown_folders_restore',
            forceDeleteRoute: 'backend_notes_markdown_folders_force_delete',
            emptyTrashRoute: 'backend_notes_markdown_folders_empty_trash',
            actionPrivilege: 'notes.markdown.use',
            listRoute: 'backend_notes_markdown',
        );
    }

    private function present(NoteFolderInterface $folder): TrashItem
    {
        $name = $folder->getName();

        return new TrashItem(
            id: (int) $folder->getId(),
            label: null !== $name && '' !== $name ? $name : '#'.$folder->getId(),
            deletedAt: $folder->getDeletedAt(),
            context: $folder->getParent()?->getName(),
        );
    }
}
