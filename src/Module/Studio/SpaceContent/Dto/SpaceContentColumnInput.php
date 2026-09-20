<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

use Aurora\Core\Support\ChartPalette;
use Symfony\Component\Validator\Constraints as Assert;

class SpaceContentColumnInput implements SpaceContentColumnInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.space_content.errors.column_name_required')]
        #[Assert\Length(max: 100, maxMessage: 'backend.studio.space_content.errors.column_name_too_long')]
        public readonly string $name = '',
        #[Assert\Range(min: 1, max: ChartPalette::MAX_SLOT)]
        public readonly ?int $colourSlot = null,
        // Vrai par défaut : un appel qui ne connaît pas le champ garde le
        // comportement d'avant plutôt que de cacher une étape sans que
        // personne l'ait demandé.
        public readonly bool $visibleToClient = true,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    public function isVisibleToClient(): bool
    {
        return $this->visibleToClient;
    }
}
