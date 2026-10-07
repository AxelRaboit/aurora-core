<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

use Aurora\Core\Support\ChartPalette;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
use Symfony\Component\Validator\Constraints as Assert;

class SpaceContentColumnInput implements SpaceContentColumnInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.studio.space_content.errors.column_name_required')]
        #[Assert\Length(max: 100, maxMessage: 'suite.studio.space_content.errors.column_name_too_long')]
        public readonly string $name = '',
        #[Assert\Range(min: 1, max: ChartPalette::MAX_SLOT)]
        public readonly ?int $colourSlot = null,
        // False by default, like everything a space can show the client: a
        // stage is shown by a deliberate act, never by an oversight.
        public readonly bool $visibleToClient = false,
        public readonly ?SpaceContentColumnRoleEnum $role = null,
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

    public function getRole(): ?SpaceContentColumnRoleEnum
    {
        return $this->role;
    }
}
