<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Symfony\Component\Validator\Constraints as Assert;

class MarkdownNoteInput implements MarkdownNoteInputInterface
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly ?int $folderId = null,
        public readonly ?string $title = null,
        public readonly ?string $content = null,
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 64)])]
        public readonly array $tags = [],
        #[Assert\PositiveOrZero]
        public readonly ?int $position = null,
        /**
         * The address of the header image.
         *
         * Only `https`: it goes into a style attribute and into a `src`,
         * and a `javascript:` or `data:` address has no business there.
         * The length is the column's.
         */
        #[Assert\Length(max: 1024)]
        #[Assert\Url(protocols: ['https'], requireTld: true)]
        public readonly ?string $coverUrl = null,
        #[Assert\Length(max: 255)]
        public readonly ?string $coverCreditName = null,
        #[Assert\Length(max: 1024)]
        #[Assert\Url(protocols: ['https'], requireTld: true)]
        public readonly ?string $coverCreditUrl = null,
        #[Assert\Range(min: 0, max: 100)]
        public readonly ?int $coverPosition = null,
        #[Assert\Choice(callback: [NoteAppearanceEnum::class, 'values'])]
        public readonly ?string $appearance = null,
        /** The version the save starts from; null for a call that does not know it. */
        public readonly ?int $version = null,
        /** Overwrite despite an outdated version: the person's explicit choice. */
        public readonly bool $force = false,
        /** The space of a creation at the root; null for the personal space. A folder imposes its own. */
        public readonly ?int $spaceId = null,
    ) {}

    public function getFolderId(): ?int
    {
        return $this->folderId;
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

    public function getCoverUrl(): ?string
    {
        return $this->coverUrl;
    }

    public function getCoverCreditName(): ?string
    {
        return $this->coverCreditName;
    }

    public function getCoverCreditUrl(): ?string
    {
        return $this->coverCreditUrl;
    }

    public function getCoverPosition(): ?int
    {
        return $this->coverPosition;
    }

    public function getAppearance(): ?string
    {
        return $this->appearance;
    }

    public function getVersion(): ?int
    {
        return $this->version;
    }

    public function isForce(): bool
    {
        return $this->force;
    }

    public function getSpaceId(): ?int
    {
        return $this->spaceId;
    }
}
