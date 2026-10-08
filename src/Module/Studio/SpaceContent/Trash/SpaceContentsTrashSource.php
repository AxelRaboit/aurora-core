<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Trash;

use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\StudioContext;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;

/**
 * The contents put in the trash, in the spaces the reader sees.
 *
 * Only those of a living space: a content of a space that is itself in the
 * trash would come back nowhere, and it comes back with its space. The row
 * names the space, because "Post du lundi" exists in each of them.
 *
 * Restoring and destroying require the right that put the content there,
 * editing the space.
 */
final readonly class SpaceContentsTrashSource implements TrashSourceInterface
{
    public function __construct(
        private SpaceContentItemRepository $itemRepository,
        private SpaceVisibility $visibility,
        private StudioContext $studioContext,
    ) {}

    public function getModuleKey(): string
    {
        return 'studio';
    }

    public function getRequiredPrivilege(): string
    {
        return 'studio.spaces.view';
    }

    public function getSummary(int $limit): TrashSummary
    {
        $rows = $this->studioContext->areSpacesEnabled()
            ? array_values(array_filter(
                $this->itemRepository->findAllTrashed(),
                fn (SpaceContentItemInterface $item): bool => $this->visibility->canSee($item->getSpace()),
            ))
            : [];

        $oldest = null;
        foreach ($rows as $row) {
            $deletedAt = $row->getDeletedAt();
            if (null !== $deletedAt && (null === $oldest || $deletedAt < $oldest)) {
                $oldest = $deletedAt;
            }
        }

        return new TrashSummary(
            key: 'studio_space_contents',
            labelKey: 'suite.nav.studio_space_contents',
            sectionId: 'studio',
            icon: 'kanban-square',
            count: count($rows),
            items: array_map($this->present(...), array_slice($rows, 0, $limit)),
            oldestDeletedAt: $oldest,
            restoreRoute: 'suite_studio_space_contents_restore',
            forceDeleteRoute: 'suite_studio_space_contents_force_delete',
            emptyTrashRoute: 'suite_studio_space_contents_empty_trash',
            actionPrivilege: 'studio.spaces.edit',
        );
    }

    private function present(SpaceContentItemInterface $item): TrashItem
    {
        return new TrashItem(
            id: (int) $item->getId(),
            label: $item->getTitle(),
            deletedAt: $item->getDeletedAt(),
            context: $item->getSpace()->getName(),
        );
    }
}
