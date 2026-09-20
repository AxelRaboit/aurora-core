<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;

interface SpaceContentColumnInterface
{
    public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface;

    public function setSpace(CustomerSpaceInterface $space): static;

    public function getName(): string;

    public function setName(string $name): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function isVisibleToClient(): bool;

    public function setVisibleToClient(bool $visibleToClient): static;

    public function getColourSlot(): ?int;

    public function setColourSlot(?int $colourSlot): static;
}
