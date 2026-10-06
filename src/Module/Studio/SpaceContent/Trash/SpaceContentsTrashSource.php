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
 * Les contenus mis à la corbeille, dans les espaces que le lecteur voit.
 *
 * Seulement ceux d'un espace vivant : un contenu d'un espace lui-même à la
 * corbeille ne reviendrait nulle part, et il revient avec son espace. La ligne
 * nomme l'espace, parce que « Post du lundi » existe dans chacun.
 *
 * Restaurer et détruire demandent le droit qui y a mis le contenu, modifier
 * l'espace.
 */
final readonly class SpaceContentsTrashSource implements TrashSourceInterface
{
    public function __construct(
        private SpaceContentItemRepository $items,
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
                $this->items->findAllTrashed(),
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
