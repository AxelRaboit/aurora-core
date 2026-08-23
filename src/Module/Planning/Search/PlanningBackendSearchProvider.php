<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Search;

use Aurora\Core\Search\BackendSearchProviderInterface;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Aurora\Module\Planning\Reminder\Repository\PlanningReminderRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * The calendar's slice of the backend global search.
 *
 * Two sections rather than one, because an event and a reminder are different
 * things - one happens at a time, the other is owed by one - and a reader
 * scanning results should not have to tell them apart by squinting at the row.
 *
 * Scoped to the signed-in reader. Global search reaches every screen, so an
 * unscoped title match is the shortest path there is to reading somebody else's
 * private calendar.
 *
 * Each row carries the path that opens it, on the day it falls. The alternative
 * was a branch in core's search that knows this module's route names, which is
 * exactly what the provider registry exists to avoid.
 */
final readonly class PlanningBackendSearchProvider implements BackendSearchProviderInterface
{
    public function __construct(
        private PlanningRepository $plannings,
        private PlanningEventRepository $events,
        private PlanningReminderRepository $reminders,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function search(string $query): array
    {
        // The contract says never throw: a search box that breaks the whole
        // palette because one module's query failed is worse than one section
        // missing.
        try {
            $user = $this->security->getUser();
            if (!$user instanceof CoreUserInterface) {
                return [];
            }

            $ids = array_map(
                static fn (PlanningInterface $planning): int => (int) $planning->getId(),
                $this->plannings->findVisibleTo($user),
            );

            if ([] === $ids) {
                return [];
            }

            return [
                'events' => $this->serializeEvents($ids, $query),
                'reminders' => $this->serializeReminders($ids, $query),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function serializeEvents(array $ids, string $query): array
    {
        $rows = [];
        foreach ($this->events->searchVisible($ids, $query) as $event) {
            $rows[] = [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'at' => $event->getStartAt()->format(DATE_ATOM),
                'allDay' => $event->isAllDay(),
                'calendar' => $event->getPlanning()->getName(),
                'colourSlot' => $event->getPlanning()->getColourSlot(),
                'path' => $this->dayPath($event->getStartAt()->format('Y-m-d')),
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function serializeReminders(array $ids, string $query): array
    {
        $rows = [];
        foreach ($this->reminders->searchVisible($ids, $query) as $reminder) {
            $rows[] = [
                'id' => $reminder->getId(),
                'title' => $reminder->getTitle(),
                'at' => $reminder->getDueAt()->format(DATE_ATOM),
                'allDay' => $reminder->isAllDay(),
                'completed' => $reminder->isCompleted(),
                'calendar' => $reminder->getPlanning()->getName(),
                'colourSlot' => $reminder->getPlanning()->getColourSlot(),
                'path' => $this->dayPath($reminder->getDueAt()->format('Y-m-d')),
            ];
        }

        return $rows;
    }

    private function dayPath(string $date): string
    {
        return $this->urlGenerator->generate('backend_planning_calendar', [
            'view' => 'day',
            'date' => $date,
        ]);
    }
}
