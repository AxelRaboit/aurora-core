<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Trash;

use Aurora\Core\Trash\CountableTrashSourceInterface;
use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;

final readonly class CategoriesTrashSource implements CountableTrashSourceInterface
{
    public function __construct(private DocumentCategoryRepository $categoryRepository) {}

    public function getModuleKey(): string
    {
        return 'ged';
    }

    public function getRequiredPrivilege(): string
    {
        return 'ged.categories.view';
    }

    public function countTrashed(): int
    {
        return count($this->categoryRepository->findAllTrashed());
    }

    public function getSummary(int $limit): TrashSummary
    {
        $trashed = $this->categoryRepository->findAllTrashed();

        return new TrashSummary(
            key: 'ged_categories',
            labelKey: 'suite.nav.ged_categories',
            sectionId: 'ged',
            icon: 'tags',
            count: count($trashed),
            items: array_map($this->present(...), array_slice($trashed, 0, $limit)),
            oldestDeletedAt: $this->categoryRepository->oldestTrashedAt(),
            restoreRoute: 'suite_ged_categories_restore',
            forceDeleteRoute: 'suite_ged_categories_force_delete',
            emptyTrashRoute: 'suite_ged_categories_empty_trash',
            actionPrivilege: 'ged.categories.delete',
            listRoute: 'suite_ged_categories',
        );
    }

    private function present(DocumentCategoryInterface $category): TrashItem
    {
        return new TrashItem(
            id: (int) $category->getId(),
            label: $category->getName(),
            deletedAt: $category->getDeletedAt(),
        );
    }
}
