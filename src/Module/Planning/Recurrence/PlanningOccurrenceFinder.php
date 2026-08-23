<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Recurrence;

use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use DateTimeImmutable;

/**
 * Everything that appears on a calendar between two instants.
 *
 * Two queries and an expansion, in one answer. The split is not an implementation
 * detail the caller should see: a screen asks what is on the calendar, and whether
 * a given appearance is a row or a date a rule produced is the expander's problem.
 *
 * Sorted by start, because that is the only order that means anything once rows
 * and generated dates are in the same list - the database sorted its half and the
 * expander sorted its own, and neither knew about the other.
 */
final readonly class PlanningOccurrenceFinder
{
    public function __construct(
        private PlanningEventRepository $events,
        private OccurrenceExpander $expander,
    ) {}

    /**
     * @param list<int> $planningIds
     *
     * @return list<PlanningOccurrence>
     */
    public function find(array $planningIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $occurrences = [];

        foreach ($this->events->findSinglesInWindow($planningIds, $from, $to) as $event) {
            $occurrences[] = PlanningOccurrence::of($event);
        }

        foreach ($this->events->findSeriesReaching($planningIds, $from, $to) as $series) {
            $occurrences = [...$occurrences, ...$this->expander->expand($series, $from, $to)];
        }

        usort(
            $occurrences,
            static fn (PlanningOccurrence $a, PlanningOccurrence $b): int => $a->startAt <=> $b->startAt,
        );

        return $occurrences;
    }
}
