<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Trash;

use Aurora\Core\Trash\CountableTrashSourceInterface;
use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;

/**
 * Lists the folders deleted on their own.
 *
 * A folder that fell with its parent comes back with it, so offering to
 * restore it separately would put a branch back under a parent that is still
 * deleted. The count matches the list for the same reason.
 */
final readonly class FoldersTrashSource implements CountableTrashSourceInterface
{
    public function __construct(private DocumentFolderRepository $folderRepository) {}

    public function getModuleKey(): string
    {
        return 'ged';
    }

    public function getRequiredPrivilege(): string
    {
        return 'ged.folders.manage';
    }

    public function countTrashed(): int
    {
        return count($this->folderRepository->findTrashedRoots());
    }

    public function getSummary(int $limit): TrashSummary
    {
        $roots = $this->folderRepository->findTrashedRoots();

        return new TrashSummary(
            key: 'ged_folders',
            labelKey: 'suite.nav.ged_folders',
            sectionId: 'ged',
            icon: 'folder',
            count: count($roots),
            items: array_map($this->present(...), array_slice($roots, 0, $limit)),
            oldestDeletedAt: $this->folderRepository->oldestTrashedAt(),
            restoreRoute: 'suite_ged_folders_restore',
            forceDeleteRoute: 'suite_ged_folders_force_delete',
            emptyTrashRoute: 'suite_ged_folders_empty_trash',
            actionPrivilege: 'ged.folders.manage',
            // Folders have no screen of their own: their tree is the media
            // library panel, and that is where you go to see them again.
            listRoute: 'suite_ged_documents',
        );
    }

    private function present(DocumentFolderInterface $folder): TrashItem
    {
        return new TrashItem(
            id: (int) $folder->getId(),
            label: $folder->getName(),
            deletedAt: $folder->getDeletedAt(),
            context: $folder->getParent()?->getName(),
        );
    }
}
