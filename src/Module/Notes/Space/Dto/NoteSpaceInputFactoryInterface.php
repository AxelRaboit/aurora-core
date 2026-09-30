<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Dto;

interface NoteSpaceInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): NoteSpaceInputInterface;
}
