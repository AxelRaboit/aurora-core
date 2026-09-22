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
        public readonly ?int $parentId = null,
        #[Assert\PositiveOrZero]
        public readonly ?int $position = null,
    ) {}

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }
}
