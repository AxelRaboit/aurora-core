<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

interface TagDeleteInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): TagDeleteInput;
}
