<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Trash;

use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSourceInterface;
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
final readonly class FoldersTrashSource implements TrashSourceInterface
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

    public function getSummary(int $limit): TrashSummary
    {
        $roots = $this->folderRepository->findTrashedRoots();

        return new TrashSummary(
            key: 'ged_folders',
            labelKey: 'backend.nav.ged_folders',
            icon: 'folder',
            count: count($roots),
            items: array_map($this->present(...), array_slice($roots, 0, $limit)),
            oldestDeletedAt: $this->folderRepository->oldestTrashedAt(),
            restoreRoute: 'backend_ged_folders_restore',
            forceDeleteRoute: 'backend_ged_folders_force_delete',
            emptyTrashRoute: 'backend_ged_folders_empty_trash',
            actionPrivilege: 'ged.folders.manage',
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
