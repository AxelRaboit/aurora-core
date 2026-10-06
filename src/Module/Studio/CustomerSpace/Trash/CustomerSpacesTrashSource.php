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
 * Les espaces clients mis à la corbeille, ceux de l'équipe du lecteur.
 *
 * Une source répond pour la personne qui regarde : un équipier voit les
 * espaces dont il est membre, un administrateur tous, par la même règle que la
 * liste des espaces ({@see SpaceVisibility::reaches()}). La ligne nomme le
 * client, parce que deux espaces d'une même agence s'appellent souvent pareil.
 *
 * Restaurer et détruire demandent le droit qui y a mis l'espace, le supprimer :
 * rendre un espace à son client est aussi lourd que le lui retirer.
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
