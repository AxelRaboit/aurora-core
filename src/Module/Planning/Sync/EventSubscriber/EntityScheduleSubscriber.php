<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Sync\EventSubscriber;

use Aurora\Core\Scheduling\Event\EntityScheduledEvent;
use Aurora\Core\Scheduling\Event\EntityUnscheduledEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\PlanningContext;
use Aurora\Module\Planning\Sync\Manager\ModuleCalendarProvider;
use Aurora\Module\Planning\Time\PlanningClock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Puts another module's dates on the calendar, and takes them off again.
 *
 * This is the half of the sync that lives in Planning. The producer knows nothing
 * about calendars - it says "this entity of mine has a date" into core, and if no
 * calendar is installed nobody is listening.
 *
 * Every event it writes is marked with its source, which the existing rules
 * already act on: `isReadOnly()` makes the manager refuse to edit it and the
 * screen leave out its buttons. Editing one would be pointless anyway - the next
 * announcement from the source rewrites it.
 *
 * Except when the producer says otherwise. A booking is a request the calendar
 * answers: it arrives tentative, stays editable, and is announced once. Its
 * status, notes and editability are set only when the entry is made, so a
 * later announcement never undoes what the calendar's owner decided.
 */
final readonly class EntityScheduleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PlanningEventRepository $events,
        private ModuleCalendarProvider $calendars,
        private EntityManagerInterface $entityManager,
        private PlanningContext $planningContext,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EntityScheduledEvent::class => 'onScheduled',
            EntityUnscheduledEvent::class => 'onUnscheduled',
        ];
    }

    public function onScheduled(EntityScheduledEvent $event): void
    {
        // Checked here rather than left to the listener never being registered:
        // a module switched off should stop acting, and the class is present
        // either way because modules are directories in one bundle.
        if (!$this->planningContext->isGloballyEnabled()) {
            return;
        }

        $existing = $this->events->findBySource($event->getSourceType(), $event->getSourceId());
        $planning = $this->calendars->forSource($event->getSourceType(), $event->getCalendarName());

        $entry = $existing ?? new PlanningEvent();
        $entry->setPlanning($planning);
        $entry->setTitle($event->getLabel());
        $entry->setSource(
            $event->getSourceType(),
            $event->getSourceId(),
            '' !== $event->getSourceLabel() ? $event->getSourceLabel() : null,
        );
        $entry->setSourceUrl($event->getUrl());
        // Null leaves the event on its calendar's colour, which is what a
        // producer that said nothing about colour means. `setColourSlot`
        // clamps, so a slot out of range is a colour rather than a failure.
        $entry->setColourSlot($event->getColourSlot());
        // A date with no end is a moment, and a moment with no duration cannot be
        // drawn: `setSpan` refuses an end before a start and accepts one equal to
        // it, so the fallback is the start itself.
        //
        // In UTC, like every instant the calendar stores: this is the edge a
        // module's dates come in by, and a booking announced at 10:00 Paris
        // time was stored as 10:00 and read back as 10:00 UTC, two hours late.
        $utc = PlanningClock::utcZone();
        $startAt = $event->getStartAt()->setTimezone($utc);
        $entry->setSpan($startAt, $event->getEndAt()?->setTimezone($utc) ?? $startAt);

        if (!$existing instanceof PlanningEventInterface) {
            $entry->setDescription($event->getDescription());
            $entry->setSourceEditable($event->isEditable());
            if ($event->isTentative()) {
                $entry->setStatus(PlanningEventStatusEnum::Tentative);
            }

            $this->entityManager->persist($entry);
        }

        $this->entityManager->flush();
    }

    public function onUnscheduled(EntityUnscheduledEvent $event): void
    {
        if (!$this->planningContext->isGloballyEnabled()) {
            return;
        }

        $existing = $this->events->findBySource($event->getSourceType(), $event->getSourceId());
        if (!$existing instanceof PlanningEventInterface) {
            // Not an error. A module announcing that something is no longer
            // scheduled has no way to know whether it ever was, and making it
            // find out first would be a query for every save.
            return;
        }

        $this->entityManager->remove($existing);
        $this->entityManager->flush();
    }
}
