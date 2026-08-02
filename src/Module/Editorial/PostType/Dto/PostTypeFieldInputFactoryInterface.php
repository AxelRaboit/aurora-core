<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Dto;

interface PostTypeFieldInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PostTypeFieldInputInterface;
}
