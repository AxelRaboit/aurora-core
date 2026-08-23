<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Recurrence;

use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use DateTimeImmutable;

/**
 * One appearance of an event on a calendar.
 *
 * A single event has exactly one, and it is the row itself. A series has as many
 * as the window being drawn contains, none of which is a row. Screens and the
 * serializer take this rather than an entity, so neither has to ask which of the
 * two it is holding.
 *
 * `occurrenceAt` is the identity of a generated appearance - the date the rule
 * produced. It is what a client sends back to say "this one", and it is null on a
 * row, because a row already has an id.
 */
final readonly class PlanningOccurrence
{
    public function __construct(
        public PlanningEventInterface $event,
        public DateTimeImmutable $startAt,
        public DateTimeImmutable $endAt,
        public ?DateTimeImmutable $occurrenceAt = null,
    ) {}

    /** A row, standing for itself. */
    public static function of(PlanningEventInterface $event): self
    {
        return new self($event, $event->getStartAt(), $event->getEndAt());
    }

    public function isGenerated(): bool
    {
        return $this->occurrenceAt instanceof DateTimeImmutable;
    }
}
