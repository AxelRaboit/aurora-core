<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Core\Scheduling\Event\EntityScheduledEvent;
use Aurora\Core\Scheduling\Event\EntityUnscheduledEvent;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentItemInputInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(SpaceContentItemManagerInterface::class)]
class SpaceContentItemManager implements SpaceContentItemManagerInterface
{
    /** What the calendar files these dates under. Part of the schema of `core_planning_events`. */
    protected const string SCHEDULE_SOURCE = 'studio.space_content';

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly SpaceContentItemRepository $itemRepository,
        protected readonly SpaceContentColumnRepository $columnRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function create(CustomerSpaceInterface $space, SpaceContentItemInputInterface $input): SpaceContentItemInterface
    {
        $item = $this->createItem();
        $item->setSpace($space);

        $this->applyInput($item, $input);
        $item->setPosition($this->itemRepository->nextPosition($item->getColumn()));

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        $this->auditCreated($item);
        $this->announceSchedule($item);

        return $item;
    }

    public function update(SpaceContentItemInterface $item, SpaceContentItemInputInterface $input): void
    {
        $previousColumn = $item->getColumn();

        $this->applyInput($item, $input);

        // A card whose step changed from the form, rather than by being
        // dragged, has to land somewhere in its new column. The bottom is
        // where a person who did not choose a place expects it.
        if ($previousColumn->getId() !== $item->getColumn()->getId()) {
            $item->setPosition($this->itemRepository->nextPosition($item->getColumn()));
        }

        $this->entityManager->flush();

        $this->auditUpdated($item);
        $this->announceSchedule($item);
    }

    public function delete(SpaceContentItemInterface $item): void
    {
        $this->auditDeleted($item);

        $id = (int) $item->getId();

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        // After the row is gone, not before: an announcement that fails must
        // not leave a card deleted from the calendar and present on the board.
        $this->eventDispatcher->dispatch(new EntityUnscheduledEvent(static::SCHEDULE_SOURCE, $id));
    }

    /** @param list<int> $itemIds */
    public function reorder(CustomerSpaceInterface $space, int $columnId, array $itemIds): void
    {
        $column = $this->resolveColumn($space, $columnId);

        $byId = [];
        foreach ($this->itemRepository->findForSpace($space) as $item) {
            $byId[(int) $item->getId()] = $item;
        }

        $position = 0;
        foreach ($itemIds as $itemId) {
            $item = $byId[$itemId] ?? null;

            // An id from another space is not an error worth a 422: the page
            // sends what it is showing, and a card deleted in another tab is
            // the ordinary way this list goes stale.
            if (null === $item) {
                continue;
            }

            $item->setColumn($column);
            $item->setPosition($position);
            ++$position;
        }

        $this->entityManager->flush();
    }

    public function reschedule(SpaceContentItemInterface $item, ?string $scheduledAt): void
    {
        $item->setScheduledAt($this->instantFrom($scheduledAt, $item->getSpace()));
        $this->entityManager->flush();

        $this->auditUpdated($item);
        $this->announceSchedule($item);
    }

    /**
     * Tells the calendar module that this card has a date, or no longer does.
     *
     * The producer knows nothing about calendars: it says so into core, and if
     * Planning is absent or switched off nobody is listening. That is the same
     * contract Editorial's posts use, and it is why a space does not draw an
     * agenda of its own.
     *
     * **One source type for every space, not one per space.** A per-space
     * source would make `ModuleCalendarProvider` create a shared, ownerless
     * calendar for each client, and `findVisibleTo` returns every shared
     * calendar to everybody: fifteen clients would put fifteen rows in every
     * member's sidebar. The colour is what tells them apart instead, and the
     * provenance line names the space.
     */
    protected function announceSchedule(SpaceContentItemInterface $item): void
    {
        $id = (int) $item->getId();
        $scheduledAt = $item->getScheduledAt();
        $space = $item->getSpace();

        if (!$scheduledAt instanceof DateTimeImmutable) {
            $this->eventDispatcher->dispatch(new EntityUnscheduledEvent(static::SCHEDULE_SOURCE, $id));

            return;
        }

        $this->eventDispatcher->dispatch(new EntityScheduledEvent(
            sourceType: static::SCHEDULE_SOURCE,
            sourceId: $id,
            label: $item->getTitle(),
            startAt: $scheduledAt,
            calendarName: $this->translator->trans('backend.studio.space_content.calendar_name'),
            // The space, not the module: a reader looking at a busy week needs
            // to know which client a date belongs to, and "Espaces clients"
            // told them the same thing eight times.
            sourceLabel: $space->getName(),
            url: $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]),
            colourSlot: $space->getColourSlot(),
        ));
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createItem(): SpaceContentItemInterface
    {
        return new SpaceContentItem();
    }

    /**
     * Hydrates the entity from the input DTO. Override in a subclass and
     * call `parent::applyInput()` FIRST so the base fields stay populated,
     * then read your own extra fields off the input.
     */
    protected function applyInput(SpaceContentItemInterface $item, SpaceContentItemInputInterface $input): void
    {
        $item
            ->setTitle($input->getTitle())
            ->setBody($input->getBody())
            ->setColumn($this->resolveColumn($item->getSpace(), $input->getColumnId()))
            ->setScheduledAt($this->instantFrom($input->getScheduledAt(), $item->getSpace()));
    }

    /**
     * The step this card is on, checked to be one of this space's.
     *
     * The check is the point. Without it a crafted payload could file a card on
     * another client's board, which is the one thing a per-client space must
     * never allow.
     */
    protected function resolveColumn(CustomerSpaceInterface $space, ?int $columnId): SpaceContentColumnInterface
    {
        $column = null === $columnId ? null : $this->columnRepository->find($columnId);

        if (!$column instanceof SpaceContentColumnInterface || $column->getSpace()->getId() !== $space->getId()) {
            throw new FieldException('columnId', $this->translator->trans('backend.studio.space_content.errors.column_required'));
        }

        return $column;
    }

    /**
     * A typed wall clock as the instant it names in the space's zone.
     *
     * Read here rather than in the browser, and in the space's zone rather than
     * the reader's: "mardi 9h" is a promise made to a client, and a person on
     * holiday in another country must not move it by opening the page.
     */
    protected function instantFrom(?string $scheduledAt, CustomerSpaceInterface $space): ?DateTimeImmutable
    {
        if (null === $scheduledAt || '' === $scheduledAt) {
            return null;
        }

        try {
            return new DateTimeImmutable($scheduledAt, new DateTimeZone($space->getTimezone()));
        } catch (Exception) {
            // The DTO's own pattern has already refused anything unparseable,
            // so reaching here means the space carries a zone the system does
            // not know. Treated as "no date" rather than as a crash: losing a
            // schedule is recoverable, a 500 on every save is not.
            return null;
        }
    }

    protected function auditCreated(SpaceContentItemInterface $item): void
    {
        $this->auditLogger->log('studio', 'space_content_item.created', 'SpaceContentItem', $item->getId(), $this->auditPayload($item));
    }

    protected function auditUpdated(SpaceContentItemInterface $item): void
    {
        $this->auditLogger->log('studio', 'space_content_item.updated', 'SpaceContentItem', $item->getId(), $this->auditPayload($item));
    }

    protected function auditDeleted(SpaceContentItemInterface $item): void
    {
        $this->auditLogger->log('studio', 'space_content_item.deleted', 'SpaceContentItem', $item->getId(), $this->auditPayload($item));
    }

    /** @return array<string, mixed> */
    protected function auditPayload(SpaceContentItemInterface $item): array
    {
        return [
            'title' => $item->getTitle(),
            'spaceId' => $item->getSpace()->getId(),
            'spaceName' => $item->getSpace()->getName(),
            'column' => $item->getColumn()->getName(),
            'scheduledAt' => $item->getScheduledAt()?->format(DATE_ATOM),
        ];
    }
}
