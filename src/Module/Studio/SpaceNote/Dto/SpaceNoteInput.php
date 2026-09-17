<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Dto;

use Aurora\Module\Studio\CustomerSpace\Entity\AbstractCustomerSpace;
use Aurora\Module\Studio\SpaceNote\Enum\SpaceNoteVisibilityEnum;
use Symfony\Component\Validator\Constraints as Assert;

class SpaceNoteInput implements SpaceNoteInputInterface
{
    /** @param list<array<string, mixed>> $body */
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.space_notes.errors.title_required')]
        #[Assert\Length(max: 180, maxMessage: 'backend.studio.space_notes.errors.title_too_long')]
        public readonly string $title = '',
        public readonly array $body = [],
        // La meme palette que partout : une note suit le theme au lieu de
        // porter une couleur que personne ne peut restyler.
        #[Assert\Range(min: 1, max: AbstractCustomerSpace::MAX_COLOUR_SLOT)]
        public readonly ?int $colourSlot = null,
        public readonly bool $pinned = false,
        // Partagee par defaut : une note prise sur l'espace d'un client parle
        // en general du travail, et un mur que personne d'autre ne peut lire
        // cesse d'etre la memoire de l'espace.
        public readonly SpaceNoteVisibilityEnum $visibility = SpaceNoteVisibilityEnum::Shared,
    ) {}

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return list<array<string, mixed>> */
    public function getBody(): array
    {
        return $this->body;
    }

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function getVisibility(): SpaceNoteVisibilityEnum
    {
        return $this->visibility;
    }
}
