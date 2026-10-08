<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Sync\Availability;

use Aurora\Core\Scheduling\Availability\ScheduleAvailabilityInterface;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Aurora\Module\Planning\PlanningContext;
use Aurora\Module\Planning\Sync\Manager\ModuleCalendarProvider;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The calendar's answer to "what is taken", for a module that offers slots.
 *
 * Reads the calendar the source's dates land in - the one
 * {@see ModuleCalendarProvider} made for
 * it - and every event on it, not only the ones the module announced: a slot
 * the owner blocked by hand is as taken as a booked one. A tentative event
 * holds its slot like a confirmed one; a cancelled one frees it.
 */
#[AsAlias(ScheduleAvailabilityInterface::class)]
final readonly class PlanningScheduleAvailability implements ScheduleAvailabilityInterface
{
    public function __construct(
        private PlanningContext $planningContext,
        private PlanningRepository $planningRepository,
        private PlanningEventRepository $eventRepository,
    ) {}

    public function isEnabled(): bool
    {
        return $this->planningContext->isGloballyEnabled();
    }

    public function busyPeriods(string $sourceType, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $planning = $this->planningRepository->findOneBy(['sourceType' => $sourceType]);

        // No calendar yet: nothing was ever booked, so nothing is taken.
        if (!$planning instanceof PlanningInterface) {
            return [];
        }

        $busy = [];
        foreach ($this->eventRepository->findSinglesInWindow([(int) $planning->getId()], $from, $to) as $event) {
            if (PlanningEventStatusEnum::Cancelled !== $event->getStatus()) {
                $busy[] = [$event->getStartAt(), $event->getEndAt()];
            }
        }

        return $busy;
    }
}
