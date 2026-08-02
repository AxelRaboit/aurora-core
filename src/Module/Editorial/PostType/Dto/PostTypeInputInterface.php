<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Dto;

interface PostTypeInputInterface
{
    public function getSlug(): string;

    public function getLabel(): string;

    public function getIcon(): ?string;

    public function hasArchive(): bool;

    /** @return list<string> */
    public function getSupports(): array;
}
