<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ce qu'on règle d'un espace : son nom, sa couleur, qui y entre, et le rôle
 * de qui entre sans inscription.
 *
 * Le nom est exigé, contrairement à un dossier : un espace partagé se
 * retrouve dans le panneau de plusieurs personnes, et « Sans titre » chez
 * chacune ne dirait à personne de quoi il s'agit.
 */
class NoteSpaceInput implements NoteSpaceInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'notes.markdown.spaces.errors.name_required')]
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
