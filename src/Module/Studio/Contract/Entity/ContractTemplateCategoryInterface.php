<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;

interface ContractTemplateCategoryInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function setName(string $name): static;

    public function getColor(): ?string;

    public function setColor(?string $color): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;
}
