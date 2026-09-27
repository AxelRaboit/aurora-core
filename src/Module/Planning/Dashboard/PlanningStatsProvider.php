<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Dashboard;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Aurora\Module\Planning\Recurrence\PlanningOccurrence;
use Aurora\Module\Planning\Recurrence\PlanningOccurrenceFinder;
use Aurora\Module\Planning\Reminder\Repository\PlanningReminderRepository;
use Aurora\Module\Planning\Time\PlanningClock;
use Aurora\Module\Planning\Sync\Access\ModuleEventVisibility;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What is coming up, on the backend dashboard.
 *
 * **Scoped to the reader, unlike Editorial's provider.** That one is deliberately
 * unscoped because "how much content does the site hold" is a question about the
 * site. "What is coming up" is a question about a person, and answering it across
 * every calendar would put somebody else's private events on your dashboard. So
 * this reads the signed-in user itself and asks `findVisibleTo`.
 *
 * Counts alone would have been cheaper and nearly useless: nobody opens a
 * dashboard to learn they own four calendars. The list of the next few things is
 * the reason to look, and the overdue count is the one figure that asks for
 * something rather than reporting it.
 */
final readonly class PlanningStatsProvider implements DashboardStatsProviderInterface
{
    /** Enough to be useful on a tile, few enough to read without scrolling. */
    private const int UPCOMING = 5;

    /** How far ahead « upcoming » looks. Beyond it, nothing is imminent. */
    private const int UPCOMING_DAYS = 60;

    public function __construct(
        private PlanningRepository $plannings,
        private PlanningOccurrenceFinder $occurrences,
        private PlanningReminderRepository $reminders,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private ModuleEventVisibility $moduleEvents,
    ) {}

    public function getModuleKey(): string
    {
        return 'planning';
    }

    public function getStats(): array
    {
        $user = $this->security->getUser();

        // No signed-in user means no calendars anybody may see. Returning the
        // empty shape rather than nothing, so the panel draws its empty state
        // instead of the reader meeting a missing key.
        if (!$user instanceof CoreUserInterface) {
            return ['planning' => $this->empty()];
        }

        $visible = $this->plannings->findVisibleTo($user);
        $ids = array_map(
            static fn (PlanningInterface $planning): int => (int) $planning->getId(),
            $visible,
        );

        $now = new DateTimeImmutable();

        return [
            'planning' => [
                'calendars' => count($visible),
                'overdue' => $this->reminders->countOverdue($ids, $now),
                // Merged and re-sorted here rather than in one query: they are
                // two tables and a UNION would have to agree on a column list
                // that the two do not share. Five plus five sorted in PHP is
                // cheaper than making the shapes match.
                'upcoming' => $this->upcoming($ids, $now),
                // Carried in the payload because a panel is handed its stats and
                // nothing else. Inventing a prop the dashboard does not pass
                // would have been a link that never appeared.
                'path' => $this->urlGenerator->generate('backend_planning_calendar'),
            ],
        ];
    }

    /**
     * The next few things, both kinds together, in the order they happen.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function upcoming(array $ids, DateTimeImmutable $now): array
    {
        $rows = [];

        // Through the occurrence engine, not the rows: a weekly meeting is one
        // row dated months ago, and asking the table for what starts after now
        // left every series out. The window starts at now, so what is going on
        // right now counts - it overlaps it. Cancelled ones are not coming.
        // Filtered before being cut: a module event the reader may not see
        // must not take one of the few places either.
        $occurrences = array_filter(
            $this->moduleEvents->filter($this->occurrences->find($ids, $now, $now->modify(sprintf('+%d days', self::UPCOMING_DAYS)))),
            static fn (PlanningOccurrence $occurrence): bool => PlanningEventStatusEnum::Cancelled !== $occurrence->event->getStatus(),
        );
        usort($occurrences, static fn (PlanningOccurrence $a, PlanningOccurrence $b): int => $a->startAt <=> $b->startAt);

        foreach (array_slice($occurrences, 0, self::UPCOMING) as $occurrence) {
            $event = $occurrence->event;
            $rows[] = [
                'kind' => 'event',
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'at' => $occurrence->startAt->format(DATE_ATOM),
                'allDay' => $event->isAllDay(),
                'calendar' => $event->getPlanning()->getName(),
                'colourSlot' => $event->getEffectiveColourSlot(),
                'path' => $this->dayPath($occurrence->startAt, $event->getPlanning()),
            ];
        }

        foreach ($this->reminders->findUpcoming($ids, $now, self::UPCOMING) as $reminder) {
            $rows[] = [
                'kind' => 'reminder',
                'id' => $reminder->getId(),
                'title' => $reminder->getTitle(),
                'at' => $reminder->getDueAt()->format(DATE_ATOM),
                'allDay' => $reminder->isAllDay(),
                'calendar' => $reminder->getPlanning()->getName(),
                'colourSlot' => $reminder->getPlanning()->getColourSlot(),
                'path' => $this->dayPath($reminder->getDueAt(), $reminder->getPlanning()),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));

        return array_slice($rows, 0, self::UPCOMING);
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return [
            'calendars' => 0,
            'overdue' => 0,
            'upcoming' => [],
            'path' => $this->urlGenerator->generate('backend_planning_calendar'),
        ];
    }

    /**
     * The day it happens on, in the calendar's day view: a row of this panel
     * named a date and led nowhere.
     */
    private function dayPath(DateTimeImmutable $at, PlanningInterface $planning): string
    {
        // The day in the calendar's own zone: a 23:30 event in Paris is on
        // Tuesday there, whatever day UTC says.
        return $this->urlGenerator->generate('backend_planning_calendar', [
            'view' => 'day',
            'date' => $at->setTimezone(PlanningClock::zone($planning))->format('Y-m-d'),
        ]);
    }
}
