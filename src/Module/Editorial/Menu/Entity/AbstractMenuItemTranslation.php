<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The label is nullable on purpose: left empty, the renderer falls back to
 * the target's own title, so a menu entry pointing at a post follows that
 * post's translations without being re-typed in every locale.
 */
#[ORM\MappedSuperclass]
abstract class AbstractMenuItemTranslation implements MenuItemTranslationInterface
{
    #[ORM\ManyToOne(targetEntity: MenuItemInterface::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected MenuItemInterface $menuItem;

    #[ORM\Column(length: 10)]
    protected string $locale;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $label = null;

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getMenuItem(): MenuItemInterface
    {
        return $this->menuItem;
    }

    public function setMenuItem(MenuItemInterface $menuItem): static
    {
        $this->menuItem = $menuItem;

        return $this;
    }
}
