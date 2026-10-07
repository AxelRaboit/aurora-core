<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A name and a place to put it.
 *
 * The name is nullable rather than required: a folder is often created before
 * its name is decided, exactly like a note, and the screens read an empty
 * name as "Sans titre". The maximum is a sanity bound, not a storage one, the
 * column being text.
 */
class NoteFolderInput implements NoteFolderInputInterface
{
    public function __construct(
        #[Assert\Length(max: 150)]
        public readonly ?string $name = null,
        /**
         * A refused colour is not silently ignored.
         *
         * The name is free, the colour is not: it ends up in a style
         * attribute, so anything that is not `#rrggbb` is a refusal and not a
         * cleaned value. The factory lets through what it receives so that
         * this constraint is the one that says so.
         */
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'notes.markdown.folders.errors.bad_color')]
        public readonly ?string $color = null,
        public readonly ?int $parentId = null,
        #[Assert\PositiveOrZero]
        public readonly ?int $position = null,
        /** The space of a creation at the root; null for one's personal space. A folder imposes its own. */
        public readonly ?int $spaceId = null,
    ) {}

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function getSpaceId(): ?int
    {
        return $this->spaceId;
    }
}
