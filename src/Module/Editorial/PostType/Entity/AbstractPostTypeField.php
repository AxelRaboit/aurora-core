<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class AbstractPostTypeField implements PostTypeFieldInterface
{
    /**
     * The field types the post editor knows how to draw. `select` reads its
     * choices from `options.choices`; the rest need no extras.
     *
     * Only types with an input behind them belong here — same rule as
     * {@see AbstractPostType::SUPPORTS}. `media` and `reference` were offered
     * on the post-types screen while the editor drew neither, so an admin
     * could define a "featured product" field and the writer would be asked
     * to type a raw database id into a text box. They come back with their
     * pickers, not before.
     */
    public const array TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'url', 'email'];

    /** Machine name, the key this field's value is stored under on a post. */
    #[ORM\Column(length: 100)]
    protected string $name;

    #[ORM\Column(length: 100)]
    protected string $label;

    #[ORM\Column(length: 50)]
    protected string $type = 'text';

    #[ORM\Column]
    protected bool $required = false;

    /** When true the value is held per locale rather than once per post. */
    #[ORM\Column]
    protected bool $translatable = false;

    /** @var array<string, mixed> Type-specific extras, e.g. the choices of a select. */
    #[ORM\Column(type: 'json')]
    protected array $options = [];

    #[ORM\Column]
    protected int $position = 0;

    #[ORM\ManyToOne(targetEntity: PostTypeInterface::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PostTypeInterface $postType;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    public function setTranslatable(bool $translatable): static
    {
        $this->translatable = $translatable;

        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getPostType(): PostTypeInterface
    {
        return $this->postType;
    }

    public function setPostType(PostTypeInterface $postType): static
    {
        $this->postType = $postType;

        return $this;
    }
}
