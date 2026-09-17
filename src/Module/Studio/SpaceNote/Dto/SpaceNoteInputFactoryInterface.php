<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Dto;

interface SpaceNoteInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceNoteInputInterface;
}
