<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Trash;

use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\StudioContext;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;

/**
 * The deliverables the reader put in the trash, and the ones they may open.
 *
 * A source answers for the person looking, and a deliverable is the clearest
 * case: a colleague's personal one is not theirs to see, even in a trash, and
 * a space's belongs to the people of that space. Each row therefore goes
 * through {@see DeliverableAccess::canRead()}, the one rule for who reads a
 * deliverable, and a row of a part of Studio that is switched off is left out.
 *
 * Without a privilege of its own: one reader may only have the spaces and
 * another only the module, and the same rows reach both through that rule.
 * Acting on a row is checked again, row by row, by the routes.
 */
final readonly class DeliverablesTrashSource implements TrashSourceInterface
{
    public function __construct(
        private DeliverableRepository $deliverables,
        private DeliverableAccess $access,
        private StudioContext $studioContext,
        private TranslatorInterface $translator,
    ) {}

    public function getModuleKey(): string
    {
        return 'studio';
    }

    public function getRequiredPrivilege(): ?string
    {
        return null;
    }

    public function getSummary(int $limit): TrashSummary
    {
        $rows = array_values(array_filter($this->deliverables->findAllTrashed(), $this->isVisible(...)));

        $oldest = null;
        foreach ($rows as $row) {
            $deletedAt = $row->getDeletedAt();
            if (null !== $deletedAt && (null === $oldest || $deletedAt < $oldest)) {
                $oldest = $deletedAt;
            }
        }

        return new TrashSummary(
            key: 'studio_deliverables',
            labelKey: 'backend.nav.studio_deliverables',
            sectionId: 'studio',
            icon: 'notebook-text',
            count: count($rows),
            items: array_map($this->present(...), array_slice($rows, 0, $limit)),
            oldestDeletedAt: $oldest,
            restoreRoute: 'backend_studio_deliverables_restore',
            forceDeleteRoute: 'backend_studio_deliverables_force_delete',
            emptyTrashRoute: 'backend_studio_deliverables_empty_trash',
            actionPrivilege: null,
            listRoute: 'backend_studio_deliverables',
        );
    }

    private function isVisible(DeliverableInterface $deliverable): bool
    {
        $served = $deliverable->isStandalone() ? $this->studioContext->areDeliverablesEnabled() : $this->studioContext->areSpacesEnabled();

        return $served && $this->access->canRead($deliverable);
    }

    private function present(DeliverableInterface $deliverable): TrashItem
    {
        $space = $deliverable->getSpace();

        return new TrashItem(
            id: (int) $deliverable->getId(),
            label: $deliverable->getTitle(),
            deletedAt: $deliverable->getDeletedAt(),
            context: $space instanceof CustomerSpaceInterface
                ? $space->getName()
                : $this->translator->trans('backend.studio.deliverables.scope.'.$deliverable->getScope()->value),
        );
    }
}
