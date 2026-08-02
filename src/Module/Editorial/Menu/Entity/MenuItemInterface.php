<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Entity;

use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Enum\MenuItemVisibilityEnum;
use Doctrine\Common\Collections\Collection;

interface MenuItemInterface
{
    public function getId(): ?int;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getTargetType(): MenuItemTargetTypeEnum;

    public function setTargetType(MenuItemTargetTypeEnum $targetType): static;

    public function getTargetId(): ?int;

    public function setTargetId(?int $targetId): static;

    public function getCustomUrl(): ?string;

    public function setCustomUrl(?string $customUrl): static;

    public function isOpenInNewTab(): bool;

    public function setOpenInNewTab(bool $openInNewTab): static;

    public function getCssClass(): ?string;

    public function setCssClass(?string $cssClass): static;

    public function getVisibility(): MenuItemVisibilityEnum;

    public function setVisibility(MenuItemVisibilityEnum $visibility): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function getParent(): ?MenuItemInterface;

    public function setParent(?MenuItemInterface $parent): static;

    /** @return Collection<int, MenuItemInterface> */
    public function getChildren(): Collection;

    public function getMenu(): MenuInterface;

    public function setMenu(MenuInterface $menu): static;

    /** @return Collection<string, MenuItemTranslationInterface> */
    public function getTranslations(): Collection;

    public function getTranslation(string $locale): ?MenuItemTranslationInterface;

    public function translate(string $locale): MenuItemTranslationInterface;

    /** @return list<MenuItemInterface> */
    public function getAncestors(): array;

    public function isDescendantOf(MenuItemInterface $candidate): bool;
}
