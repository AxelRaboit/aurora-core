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
         * Une couleur refusée n'est pas silencieusement ignorée.
         *
         * Le nom est libre, la couleur non : elle finit dans un attribut de
         * style, donc tout ce qui n'est pas `#rrggbb` est un refus et non
         * une valeur nettoyée. La fabrique laisse passer ce qu'elle reçoit
         * pour que ce soit cette contrainte qui le dise.
         */
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'notes.markdown.folders.errors.bad_color')]
        public readonly ?string $color = null,
        public readonly ?int $parentId = null,
        #[Assert\PositiveOrZero]
        public readonly ?int $position = null,
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
}
