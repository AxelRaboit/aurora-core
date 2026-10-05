<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Trash;

use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\StudioContext;

use function array_map;
use function array_slice;
use function count;

/**
 * The presentations that were put in the trash.
 *
 * A deck belongs to nobody in particular (no personal ones, no space), so the
 * same rows go to everyone who may see the module's decks. Acting on them
 * needs the right to delete, the one that put them here. A module switched off
 * shows nothing: its decks are not reachable, so neither is their trash.
 */
final readonly class DecksTrashSource implements TrashSourceInterface
{
    public function __construct(
        private DeckRepository $decks,
        private StudioContext $studioContext,
    ) {}

    public function getModuleKey(): string
    {
        return 'studio';
    }

    public function getRequiredPrivilege(): string
    {
        return 'studio.decks.view';
    }

    public function getSummary(int $limit): TrashSummary
    {
        $rows = $this->studioContext->areDecksEnabled() ? $this->decks->findAllTrashed() : [];

        $oldest = null;
        foreach ($rows as $row) {
            $deletedAt = $row->getDeletedAt();
            if (null !== $deletedAt && (null === $oldest || $deletedAt < $oldest)) {
                $oldest = $deletedAt;
            }
        }

        return new TrashSummary(
            key: 'studio_decks',
            labelKey: 'backend.nav.studio_decks',
            sectionId: 'studio',
            icon: 'presentation',
            count: count($rows),
            items: array_map($this->present(...), array_slice($rows, 0, $limit)),
            oldestDeletedAt: $oldest,
            restoreRoute: 'backend_studio_decks_restore',
            forceDeleteRoute: 'backend_studio_decks_force_delete',
            emptyTrashRoute: 'backend_studio_decks_empty_trash',
            actionPrivilege: 'studio.decks.delete',
            listRoute: 'backend_studio_decks',
        );
    }

    private function present(DeckInterface $deck): TrashItem
    {
        $customer = $deck->getCustomer();

        return new TrashItem(
            id: (int) $deck->getId(),
            label: $deck->getTitle(),
            deletedAt: $deck->getDeletedAt(),
            context: $customer instanceof CustomerInterface ? $customer->getLegalName() : null,
        );
    }
}
