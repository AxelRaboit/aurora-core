<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Dto;

interface CommentInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CommentInputInterface;
}
