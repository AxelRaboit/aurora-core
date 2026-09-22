<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Dto;

interface NoteFolderInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): NoteFolderInputInterface;
}
