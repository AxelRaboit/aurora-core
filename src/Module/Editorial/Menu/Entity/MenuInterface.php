<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Entity;

use Doctrine\Common\Collections\Collection;

interface MenuInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function setName(string $name): static;

    public function getLocation(): string;

    public function setLocation(string $location): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    /** @return Collection<int, MenuItemInterface> */
    public function getItems(): Collection;

    public function findItemById(int $itemId): ?MenuItemInterface;
}
