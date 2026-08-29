<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

interface MarkdownNoteInputInterface
{
    public function getParentId(): ?int;

    public function getTitle(): ?string;

    public function getContent(): ?string;

    /** @return list<string> */
    public function getTags(): array;

    public function getPosition(): ?int;
}
