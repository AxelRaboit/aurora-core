<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Dto;

interface PostTypeFieldInputInterface
{
    public function getName(): string;

    public function getLabel(): string;

    public function getType(): string;

    public function isRequired(): bool;

    public function isTranslatable(): bool;

    /** @return array<string, mixed> */
    public function getOptions(): array;
}
