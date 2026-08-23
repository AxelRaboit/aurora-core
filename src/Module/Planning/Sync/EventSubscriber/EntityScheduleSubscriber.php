<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Sync\EventSubscriber;

use Aurora\Core\Scheduling\Event\EntityScheduledEvent;
use Aurora\Core\Scheduling\Event\EntityUnscheduledEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\PlanningContext;
use Aurora\Module\Planning\Sync\Service\ModuleCalendarProvider;
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
 * already act on: `isFromModule()` makes the manager refuse to edit it and the
 * screen leave out its buttons. Editing one would be pointless anyway - the next
 * announcement from the source rewrites it.
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
        if (!$this->planningContext->isBackendEnabled()) {
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
        // A date with no end is a moment, and a moment with no duration cannot be
        // drawn: `setSpan` refuses an end before a start and accepts one equal to
        // it, so the fallback is the start itself.
        $entry->setSpan($event->getStartAt(), $event->getEndAt() ?? $event->getStartAt());

        if (!$existing instanceof PlanningEventInterface) {
            $this->entityManager->persist($entry);
        }

        $this->entityManager->flush();
    }

    public function onUnscheduled(EntityUnscheduledEvent $event): void
    {
        if (!$this->planningContext->isBackendEnabled()) {
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
