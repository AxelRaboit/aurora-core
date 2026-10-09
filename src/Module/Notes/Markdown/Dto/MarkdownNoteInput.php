<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Aurora\Module\Notes\Markdown\Enum\NoteFontEnum;
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
        /*
         * Since 09/10/2026. Null means "not sent, leave as it is": a caller
         * that does not know these fields must not wipe them.
         */
        /** The emoji; "" removes it. */
        #[Assert\Length(max: 16)]
        public readonly ?string $icon = null,
        /** @var list<array<string, mixed>>|null */
        #[Assert\Count(max: 30)]
        public readonly ?array $properties = null,
        public readonly ?bool $locked = null,
        public readonly ?bool $fullWidth = null,
        public readonly ?bool $smallText = null,
        #[Assert\Choice(callback: [NoteFontEnum::class, 'values'])]
        public readonly ?string $font = null,
    ) {}

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getProperties(): ?array
    {
        return $this->properties;
    }

    public function getLocked(): ?bool
    {
        return $this->locked;
    }

    public function getFullWidth(): ?bool
    {
        return $this->fullWidth;
    }

    public function getSmallText(): ?bool
    {
        return $this->smallText;
    }

    public function getFont(): ?string
    {
        return $this->font;
    }

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
