<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class MarkdownNoteInput implements MarkdownNoteInputInterface
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly ?int $parentId = null,
        public readonly ?string $title = null,
        public readonly ?string $content = null,
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 64)])]
        public readonly array $tags = [],
        #[Assert\PositiveOrZero]
        public readonly ?int $position = null,
    ) {}

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }
}
