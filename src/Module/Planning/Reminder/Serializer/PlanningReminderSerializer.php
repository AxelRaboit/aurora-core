<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Serializer;

use Aurora\Module\Planning\Reminder\Entity\PlanningReminderInterface;
use DateTimeImmutable;

/**
 * A reminder, as a grid draws it.
 *
 * Carries `kind` even though it only ever says one thing. The grids hold events
 * and reminders in one list and branch on it, and a discriminator the payload
 * states beats every screen inferring the type from which fields happen to be
 * present.
 */
final readonly class PlanningReminderSerializer
{
    /** @return array<string, mixed> */
    public function serialize(PlanningReminderInterface $reminder, ?DateTimeImmutable $now = null): array
    {
        $planning = $reminder->getPlanning();
        $now ??= new DateTimeImmutable();

        return [
            'kind' => 'reminder',
            'id' => $reminder->getId(),
            'title' => $reminder->getTitle(),
            'notes' => $reminder->getNotes(),
            'dueAt' => $reminder->getDueAt()->format(DATE_ATOM),
            'allDay' => $reminder->isAllDay(),
            'completed' => $reminder->isCompleted(),
            'completedAt' => $reminder->getCompletedAt()?->format(DATE_ATOM),
            // Computed here rather than left to the screen: "late" is a
            // comparison against now, and a browser with a wrong clock would
            // otherwise strike through things that are not late at all.
            'overdue' => !$reminder->isCompleted() && $reminder->getDueAt() < $now,
            'planningId' => $planning->getId(),
            'planningName' => $planning->getName(),
            'colourSlot' => $planning->getColourSlot(),
        ];
    }

    /**
     * @param iterable<PlanningReminderInterface> $reminders
     *
     * @return list<array<string, mixed>>
     */
    public function serializeMany(iterable $reminders, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        $out = [];
        foreach ($reminders as $reminder) {
            $out[] = $this->serialize($reminder, $now);
        }

        return $out;
    }
}
