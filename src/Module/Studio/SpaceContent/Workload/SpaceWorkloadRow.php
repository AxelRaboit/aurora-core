<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Workload;

use DateTimeImmutable;

/**
 * What one space has waiting, in the five states every screen shares.
 *
 * See {@see SpaceWorkload} for what each one means. A value, not an entity:
 * it is computed on demand and never stored, so it cannot go stale.
 */
final readonly class SpaceWorkloadRow
{
    public function __construct(
        public int $spaceId,
        /** Dated, on the calendar, not published yet, within the horizon. */
        public int $upcoming = 0,
        /** In a step the client sees, dated, on the calendar, unanswered. */
        public int $withClient = 0,
        /** With the client, past its review deadline. */
        public int $lateReview = 0,
        /** The client asked for changes. */
        public int $changesRequested = 0,
        /** Its publication date passed and it is still not published. */
        public int $missed = 0,
        public ?DateTimeImmutable $nextPublication = null,
    ) {}

    /** Whether anything here calls for somebody to act. */
    public function needsAttention(): bool
    {
        return $this->missed + $this->lateReview + $this->changesRequested + $this->withClient > 0;
    }

    /**
     * Most urgent first: what already went wrong, then what is overdue, then
     * what came back to the studio, then what sits with the client.
     *
     * @return array{int, int, int, int}
     */
    public function urgency(): array
    {
        return [$this->missed, $this->lateReview, $this->changesRequested, $this->withClient];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'upcoming' => $this->upcoming,
            'withClient' => $this->withClient,
            'lateReview' => $this->lateReview,
            'changesRequested' => $this->changesRequested,
            'missed' => $this->missed,
            'nextPublication' => $this->nextPublication?->format(DATE_ATOM),
        ];
    }
}
