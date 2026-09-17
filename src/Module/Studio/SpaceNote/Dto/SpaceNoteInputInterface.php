<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Dto;

interface SpaceNoteInputInterface
{
    public function getTitle(): string;

    /** @return list<array<string, mixed>> */
    public function getBody(): array;

    public function getColourSlot(): ?int;

    public function isPinned(): bool;
}
