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
            // Offsets only. The form draws its own labels from them, and
            // remindAt is derived from the start the client already has.
            'alerts' => $this->alertOffsets($event),
            'readOnly' => $event->isFromModule(),
        ];
    }

    /** @return list<int> */
    private function alertOffsets(PlanningEventInterface $event): array
    {
        $offsets = [];
        foreach ($event->getAlerts() as $alert) {
            $offsets[] = $alert->getMinutesBefore();
        }

        sort($offsets);

        return $offsets;
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
