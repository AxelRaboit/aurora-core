<?php

declare(strict_types=1);

namespace Aurora\Core\Scheduling\Event;

use Aurora\Core\Contact\Event\ContactSignalEvent;
use DateTimeImmutable;

/**
 * Cross-module signal that one of a module's entities has a date.
 *
 * Lives in core for the same reason {@see ContactSignalEvent}
 * does: the producers (Editorial, and whatever comes next) and the consumer
 * (Planning) never depend on one another - they only know this event. With no
 * calendar installed it has no listener and is a harmless no-op.
 *
 * This is what makes a calendar inside Aurora different from a calendar beside
 * it: a scheduled publication is already a date somebody set, and asking them to
 * write it down a second time is asking them to keep two things in step by hand.
 *
 * `sourceType` identifies the kind of thing ('editorial.post'), `sourceId` the
 * row. Together they are the identity the calendar upserts against, so a module
 * announcing the same entity twice moves one calendar entry rather than making a
 * second.
 */
class EntityScheduledEvent
{
    public function __construct(
        private readonly string $sourceType,
        private readonly int $sourceId,
        /** What the calendar should call it - usually the entity's own title. */
        private readonly string $label,
        private readonly DateTimeImmutable $startAt,
        /** Null for a moment rather than a span, which is what most dates are. */
        private readonly ?DateTimeImmutable $endAt = null,
        /**
         * What the module's calendar is called, in the producer's own words.
         *
         * Passed rather than derived from `sourceType`, so the name is a
         * translation the owning module controls instead of a slug the calendar
         * would have to know how to spell.
         */
        private readonly string $calendarName = '',
        /**
         * What to say this came from - the module's name, not the entity's.
         *
         * Distinct from `label`, which is the entity's own title: the calendar
         * shows one as the event and the other as its provenance.
         */
        private readonly string $sourceLabel = '',
        /** Where to send a reader who wants the thing itself, if it has a page. */
        private readonly ?string $url = null,
        /**
         * Which palette slot to draw this date in, when the module has a
         * reason to prefer one.
         *
         * Null for a producer that has nothing to say about colour, which is
         * the common case: the entry then wears the colour of the calendar it
         * lands in, and one module's dates read as one module's dates.
         *
         * It exists for the producer that announces dates on behalf of several
         * things at once. A client space sends every space's schedule into one
         * calendar - one per space would put a row in everybody's sidebar for
         * every client - and without this they would all arrive the same
         * colour, which is the calendar saying "these are all the same thing"
         * about work for five different companies.
         */
        private readonly ?int $colourSlot = null,
    ) {}

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): int
    {
        return $this->sourceId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getStartAt(): DateTimeImmutable
    {
        return $this->startAt;
    }

    public function getEndAt(): ?DateTimeImmutable
    {
        return $this->endAt;
    }

    public function getCalendarName(): string
    {
        return $this->calendarName;
    }

    public function getSourceLabel(): string
    {
        return $this->sourceLabel;
    }

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }
}
