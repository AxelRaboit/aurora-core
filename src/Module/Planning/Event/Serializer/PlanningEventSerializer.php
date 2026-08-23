<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Serializer;

use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An event, as a grid draws it.
 *
 * Carries `readOnly` rather than leaving each screen to work out that an event
 * owned by a module cannot be edited. One computed flag beats the same
 * `sourceType !== null` written in a month cell, a popover and a form - and it
 * is the flag the manager enforces, so the screen and the write agree by
 * construction.
 */
final readonly class PlanningEventSerializer
{
    public function __construct(private TranslatorInterface $translator) {}

    /** @return array<string, mixed> */
    public function serialize(PlanningEventInterface $event): array
    {
        $planning = $event->getPlanning();

        return [
            // Stated, not inferred. The grids hold events and reminders in one
            // list, and a discriminator in the payload beats every screen
            // guessing the type from which fields happen to be present.
            'kind' => 'event',
            'id' => $event->getId(),
            'title' => $event->getTitle(),
            'description' => $event->getDescription(),
            'location' => $event->getLocation(),
            'startAt' => $event->getStartAt()->format(DATE_ATOM),
            'endAt' => $event->getEndAt()->format(DATE_ATOM),
            'allDay' => $event->isAllDay(),
            'status' => $event->getStatus()->value,
            'statusLabel' => $this->translator->trans($event->getStatus()->getLabelKey()),
            'statusColor' => $event->getStatus()->badgeColor(),
            'planningId' => $planning->getId(),
            'planningName' => $planning->getName(),
            // The colour comes down with the event so a chip needs one payload
            // and not a lookup into the calendar list.
            'colourSlot' => $planning->getColourSlot(),
            'sourceLabel' => $event->getSourceLabel(),
            'sourceUrl' => $event->getSourceUrl(),
            'alerts' => $this->alerts($event),
            'readOnly' => $event->isFromModule(),
        ];
    }

    /**
     * The alerts, each saying which kind it is.
     *
     * A relative one sends its offset and nothing else - the form draws its own
     * label from that, and `remindAt` is derived from a start the client already
     * has. A pinned one sends the moment, because there is nothing to derive it
     * from.
     *
     * Sorted by when they fire, which is the order a reader thinks about them in
     * and the only order that makes sense across the two kinds.
     *
     * @return list<array{minutes: int|null, at: string|null}>
     */
    private function alerts(PlanningEventInterface $event): array
    {
        $alerts = [];
        foreach ($event->getAlerts() as $alert) {
            $alerts[] = [
                'minutes' => $alert->getMinutesBefore(),
                'at' => $alert->isRelative() ? null : $alert->getRemindAt()->format(DATE_ATOM),
                // Sent for both kinds, so a screen can show when a relative alert
                // actually lands without recomputing the subtraction itself.
                'firesAt' => $alert->getRemindAt()->format(DATE_ATOM),
            ];
        }

        usort($alerts, static fn (array $a, array $b): int => strcmp((string) $a['firesAt'], (string) $b['firesAt']));

        return $alerts;
    }

    /**
     * @param iterable<PlanningEventInterface> $events
     *
     * @return list<array<string, mixed>>
     */
    public function serializeMany(iterable $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = $this->serialize($event);
        }

        return $out;
    }
}
