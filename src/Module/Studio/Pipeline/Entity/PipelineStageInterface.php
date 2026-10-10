<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Studio\Pipeline\Enum\PipelineStageRoleEnum;

interface PipelineStageInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function setName(string $name): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    /** Null means the stage wears no colour, which is a choice and not an absence. */
    public function getColourSlot(): ?int;

    public function setColourSlot(?int $colourSlot): static;

    public function getRole(): ?PipelineStageRoleEnum;

    public function setRole(?PipelineStageRoleEnum $role): static;

    public function isWon(): bool;

    public function isLost(): bool;
}
