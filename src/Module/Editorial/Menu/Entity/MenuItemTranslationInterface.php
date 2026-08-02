<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Entity;

interface MenuItemTranslationInterface
{
    public function getId(): ?int;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getLabel(): ?string;

    public function setLabel(?string $label): static;

    public function getMenuItem(): MenuItemInterface;

    public function setMenuItem(MenuItemInterface $menuItem): static;
}
