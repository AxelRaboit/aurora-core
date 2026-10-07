<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Trash;

use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\StudioContext;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;

/**
 * The customer spaces moved to the trash, those of the reader's team.
 *
 * A source answers for the person looking: a teammate sees the spaces they
 * are a member of, an administrator all of them, by the same rule as the
 * spaces list ({@see SpaceVisibility::reaches()}). The row names the
 * customer, because two spaces of the same agency are often named alike.
 *
 * Restoring and destroying require the right that put the space there,
 * deleting it: giving a space back to its customer weighs as much as taking
 * it away.
 */
final readonly class CustomerSpacesTrashSource implements TrashSourceInterface
{
    public function __construct(
        private CustomerSpaceRepository $spaces,
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
            ? array_values(array_filter($this->spaces->findAllTrashed(), $this->visibility->reaches(...)))
            : [];

        $oldest = null;
        foreach ($rows as $row) {
            $deletedAt = $row->getDeletedAt();
            if (null !== $deletedAt && (null === $oldest || $deletedAt < $oldest)) {
                $oldest = $deletedAt;
            }
        }

        return new TrashSummary(
            key: 'studio_spaces',
            labelKey: 'suite.nav.studio_spaces',
            sectionId: 'studio',
            icon: 'panels-top-left',
            count: count($rows),
            items: array_map($this->present(...), array_slice($rows, 0, $limit)),
            oldestDeletedAt: $oldest,
            restoreRoute: 'suite_studio_spaces_restore',
            forceDeleteRoute: 'suite_studio_spaces_force_delete',
            emptyTrashRoute: 'suite_studio_spaces_empty_trash',
            actionPrivilege: 'studio.spaces.delete',
            listRoute: 'suite_studio_spaces',
        );
    }

    private function present(CustomerSpaceInterface $space): TrashItem
    {
        return new TrashItem(
            id: (int) $space->getId(),
            label: $space->getName(),
            deletedAt: $space->getDeletedAt(),
            context: $space->getCustomer()->getLegalName(),
        );
    }
}
