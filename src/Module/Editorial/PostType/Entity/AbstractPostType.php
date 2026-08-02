<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class AbstractPostType implements PostTypeInterface
{
    /**
     * What a post of this type can carry. `blocks` gives it a body in the
     * block editor, `thumbnail` a featured image. Only capabilities with
     * something behind them belong here — an entry nothing reads is a
     * checkbox that lies to the editor.
     */
    public const array SUPPORTS = ['blocks', 'thumbnail'];

    #[ORM\Column(length: 100, unique: true)]
    protected string $slug;

    #[ORM\Column(length: 100)]
    protected string $label;

    #[ORM\Column(length: 50, nullable: true)]
    protected ?string $icon = null;

    #[ORM\Column]
    protected bool $hasArchive = false;

    /**
     * Set on the types the bootstrap creates (page, article). Built-in
     * types cannot be deleted and keep their slug, since routes and
     * content already point at them.
     */
    #[ORM\Column]
    protected bool $isBuiltIn = false;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    protected array $supports = self::SUPPORTS;

    /** @var Collection<int, PostTypeFieldInterface> */
    #[ORM\OneToMany(targetEntity: PostTypeFieldInterface::class, mappedBy: 'postType', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => Order::Ascending->value])]
    protected Collection $fields;

    public function __construct()
    {
        $this->fields = new ArrayCollection();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function hasArchive(): bool
    {
        return $this->hasArchive;
    }

    public function setHasArchive(bool $hasArchive): static
    {
        $this->hasArchive = $hasArchive;

        return $this;
    }

    public function isBuiltIn(): bool
    {
        return $this->isBuiltIn;
    }

    public function setIsBuiltIn(bool $isBuiltIn): static
    {
        $this->isBuiltIn = $isBuiltIn;

        return $this;
    }

    public function getSupports(): array
    {
        return $this->supports;
    }

    public function setSupports(array $supports): static
    {
        $this->supports = array_values(array_intersect(self::SUPPORTS, $supports));

        return $this;
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->supports, true);
    }

    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function findFieldById(int $fieldId): ?PostTypeFieldInterface
    {
        foreach ($this->fields as $field) {
            if ($field->getId() === $fieldId) {
                return $field;
            }
        }

        return null;
    }

    public function addField(PostTypeFieldInterface $field): static
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
            $field->setPostType($this);
        }

        return $this;
    }

    public function removeField(PostTypeFieldInterface $field): static
    {
        $this->fields->removeElement($field);

        return $this;
    }
}
