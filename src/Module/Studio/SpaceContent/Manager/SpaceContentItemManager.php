<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Core\Scheduling\Event\EntityScheduledEvent;
use Aurora\Core\Scheduling\Event\EntityUnscheduledEvent;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Service\SpaceActivityNotifier;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentItemInputInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
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
    public const string SCHEDULE_SOURCE = 'studio.space_content';

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly SpaceContentItemRepository $itemRepository,
        protected readonly SpaceContentColumnRepository $columnRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly SpaceActivityNotifier $notifier,
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
        $previousTitle = $item->getTitle();
        $previousBody = $item->getBody();
        $previousScheduledAt = $item->getScheduledAt();

        $this->applyInput($item, $input);
        $this->clearApprovalIfContentChanged($item, $previousTitle, $previousBody, $previousScheduledAt);

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

    /**
     * Says every card of this space again, after the space itself changed.
     *
     * The calendar entry carries the space's name and colour, and an archived
     * space's cards leave the calendar: renaming, recolouring or archiving a
     * space left the old version on every one of its dates.
     */
    public function announceSpace(CustomerSpaceInterface $space): void
    {
        foreach ($this->itemRepository->findForSpace($space) as $item) {
            $this->announceSchedule($item);
        }
    }

    /**
     * Takes every card of this space off the calendar, before the space goes.
     *
     * Deleting a space removes its cards by cascade, which no announcement
     * follows: their dates stayed on the calendar, pointing at a space that
     * no longer exists.
     */
    public function unscheduleSpace(CustomerSpaceInterface $space): void
    {
        foreach ($this->itemRepository->findForSpace($space) as $item) {
            $this->eventDispatcher->dispatch(new EntityUnscheduledEvent(static::SCHEDULE_SOURCE, (int) $item->getId()));
        }
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
        $previousScheduledAt = $item->getScheduledAt();
        $item->setScheduledAt($this->instantFrom($scheduledAt, $item->getSpace()));
        $this->clearApprovalIfContentChanged($item, $item->getTitle(), $item->getBody(), $previousScheduledAt);
        $this->entityManager->flush();

        $this->auditUpdated($item);
        $this->announceSchedule($item);
    }

    /**
     * Records what a client answered through their link.
     *
     * The verdict and nothing else. Words are messages on the thread, posted by
     * their own call: this used to take a note and forward it, which put a
     * second box on the client's screen beside the one they were already
     * typing in.
     *
     * The right is checked by the caller, which holds the link; what is checked
     * here is the one thing a Manager can own - that the card belongs to the
     * space the link opens. A crafted payload naming another client's card is
     * the only interesting attack on this endpoint, and it stops here.
     */
    public function answer(
        SpaceContentItemInterface $item,
        SpaceAccessLinkInterface $link,
        SpaceContentApprovalEnum $approval,
    ): void {
        if ($item->getSpace()->getId() !== $link->getSpace()->getId()) {
            throw new FieldException('item', $this->translator->trans('backend.studio.space_content.errors.not_in_space'));
        }

        $item->answer($approval, $link, new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditAnswered($item);

        // Never folded into an earlier one: answering twice is changing one's
        // mind, and the second answer is the one that counts.
        $this->notifier->clientAnswered($item, $link->getRecipientEmail(), SpaceContentApprovalEnum::Approved === $approval);
    }

    public function approveMany(array $items, SpaceAccessLinkInterface $link): int
    {
        $at = new DateTimeImmutable();
        $approved = [];

        foreach ($items as $item) {
            if ($item->getSpace()->getId() !== $link->getSpace()->getId()) {
                continue;
            }

            $item->answer(SpaceContentApprovalEnum::Approved, $link, $at);
            $approved[] = $item;
        }

        if ([] === $approved) {
            return 0;
        }

        $this->entityManager->flush();

        // One line per card still: the audit is read card by card, and a
        // grouped line would not answer "who approved this one". Written in
        // one flush.
        $this->auditLogger->logMany('studio', 'space_content_item.answered', 'SpaceContentItem', array_map(
            fn (SpaceContentItemInterface $item): array => ['id' => $item->getId(), 'data' => $this->answeredPayload($item)],
            $approved,
        ));

        if (1 === count($approved)) {
            $this->notifier->clientAnswered($approved[0], $link->getRecipientEmail(), true);
        } else {
            $this->notifier->clientApprovedMany($link->getSpace(), $link->getRecipientEmail(), count($approved));
        }

        return count($approved);
    }

    /**
     * Drops an answer whose content has changed under it.
     *
     * An approval is of what the client was shown - the wording, the date it
     * goes out, the visual - so changing any of them makes it evidence of
     * nothing, and keeping it would tell the board a client agreed to
     * something they never saw. Only the text used to count: a card approved
     * for Tuesday and moved to Friday still read « validé ». Moving a card
     * between steps leaves the answer alone; the visual is looked after by
     * {@see SpaceContentAttachmentManager}.
     */
    protected function clearApprovalIfContentChanged(
        SpaceContentItemInterface $item,
        string $previousTitle,
        ?string $previousBody,
        ?DateTimeImmutable $previousScheduledAt,
    ): void {
        if (!$item->getApproval()->isAnswered()) {
            return;
        }

        if ($item->getTitle() === $previousTitle
            && $item->getBody() === $previousBody
            && $item->getScheduledAt()?->getTimestamp() === $previousScheduledAt?->getTimestamp()) {
            return;
        }

        $item->clearApproval();
    }

    /**
     * Tells the calendar module that this card has a date, or no longer does.
     *
     * The producer knows nothing about calendars: it says so into core, and if
     * Planning is absent or switched off nobody is listening. That is the same
     * contract Editorial's posts use: the space draws its own month, and the
     * calendar module shows the same dates beside everything else.
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

        // **Décochée vaut non datée, ici aussi.** Une carte retirée du
        // calendrier de l'espace mais qui resterait dans l'agenda partagé du
        // studio ferait mentir la case : « ne pas afficher dans le
        // calendrier » se lit comme valant pour tous les calendriers, et
        // c'est le seul endroit où cette règle peut être dite une fois.
        // Et une carte d'un espace archivé non plus : son travail est fini, et
        // elle encombrerait l'agenda de ceux qui s'occupent des autres.
        if (!$scheduledAt instanceof DateTimeImmutable || !$item->appearsOnCalendar() || $space->isArchived()) {
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
            // La fiche, pas seulement l'espace : l'agenda mène à ce qu'il montre.
            url: $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId(), 'view' => 'calendar', 'item' => $item->getId()]),
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
            ->setScheduledAt($this->instantFrom($input->getScheduledAt(), $item->getSpace()))
            ->setReviewBy($this->instantFrom($input->getReviewBy(), $item->getSpace()))
            ->setShowOnCalendar($input->isShownOnCalendar());
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

    protected function auditAnswered(SpaceContentItemInterface $item): void
    {
        $this->auditLogger->log('studio', 'space_content_item.answered', 'SpaceContentItem', $item->getId(), $this->answeredPayload($item));
    }

    /** @return array<string, mixed> */
    protected function answeredPayload(SpaceContentItemInterface $item): array
    {
        return [
            ...$this->auditPayload($item),
            'approval' => $item->getApproval()->value,
            // Who, by the address they hold: there is no account behind this,
            // and the email the link was sent to is the only name there is.
            'answeredBy' => $item->getApprovalByLink()?->getRecipientEmail(),
        ];
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
