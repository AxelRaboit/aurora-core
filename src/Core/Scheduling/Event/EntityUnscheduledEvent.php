<?php

declare(strict_types=1);

namespace Aurora\Core\Scheduling\Event;

/**
 * The other half of {@see EntityScheduledEvent}: this entity no longer has a date.
 *
 * A separate event and not a scheduled one with a null date, because they are
 * different statements and the consumer does different things with them. "It
 * moved to no date" has to remove the calendar entry; a nullable date on the
 * scheduled event would make every listener check which of the two it was
 * holding, and the one that forgot would leave a publication on the calendar
 * after it was cancelled.
 */
class EntityUnscheduledEvent
{
    public function __construct(
        private readonly string $sourceType,
        private readonly int $sourceId,
    ) {}

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): int
    {
        return $this->sourceId;
    }
}
