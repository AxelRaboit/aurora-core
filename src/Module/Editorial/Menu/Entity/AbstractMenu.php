<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractMenu implements MenuInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 100)]
    protected string $name;

    /**
     * Where the theme renders this menu — `primary`, `footer`, `account`.
     * Unique: a location holds one menu, so a template asking for it never
     * has to choose between two.
     */
    #[ORM\Column(length: 100, unique: true)]
    protected string $location;

    #[ORM\Column(length: 500, nullable: true)]
    protected ?string $description = null;

    /** @var Collection<int, MenuItemInterface> */
    #[ORM\OneToMany(targetEntity: MenuItemInterface::class, mappedBy: 'menu', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => Order::Ascending->value])]
    protected Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function findItemById(int $itemId): ?MenuItemInterface
    {
        foreach ($this->items as $item) {
            if ($item->getId() === $itemId) {
                return $item;
            }
        }

        return null;
    }
}
