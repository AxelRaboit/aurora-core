<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Dto;

interface CommentInputInterface
{
    public function getAuthorName(): string;

    public function getAuthorEmail(): string;

    public function getContent(): string;

    public function getParentId(): ?int;

    public function getHoneypot(): string;
}
