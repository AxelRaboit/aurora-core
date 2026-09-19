<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

interface SpaceContentItemInputInterface
{
    public function getTitle(): string;

    public function getBody(): ?string;

    public function getColumnId(): ?int;

    /**
     * The wall clock somebody typed, as `Y-m-d\TH:i`, or null.
     *
     * A string and not a `DateTimeImmutable`, because the zone it is read in
     * belongs to the space and the DTO has no space. The Manager holds both and
     * is where the two meet.
     */
    public function getScheduledAt(): ?string;

    public function isShownOnCalendar(): bool;
}
