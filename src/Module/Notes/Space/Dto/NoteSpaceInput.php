<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What is configured on a space: its name, its colour, who gets in, and the
 * role of whoever gets in without being a member.
 *
 * The name is required, unlike a folder's: a shared space shows up in the
 * panel of several people, and "Sans titre" for each of them would tell
 * nobody what it is about.
 */
class NoteSpaceInput implements NoteSpaceInputInterface
{
    public function __construct(
        // Required for a shared space only: the controller checks it, since
        // the personal space has no name.
        #[Assert\Length(max: 120)]
        public readonly ?string $name = null,
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'notes.markdown.folders.errors.bad_color')]
        public readonly ?string $color = null,
        #[Assert\Choice(choices: ['private', 'members', 'backoffice'], message: 'notes.markdown.spaces.errors.bad_access')]
        public readonly ?string $access = null,
        #[Assert\Choice(choices: ['reader', 'editor', 'manager'], message: 'notes.markdown.spaces.errors.bad_role')]
        public readonly ?string $defaultRole = null,
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

    public function getAccess(): ?string
    {
        return $this->access;
    }

    public function getDefaultRole(): ?string
    {
        return $this->defaultRole;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }
}
