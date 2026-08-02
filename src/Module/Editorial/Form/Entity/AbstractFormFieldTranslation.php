<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class AbstractFormFieldTranslation implements FormFieldTranslationInterface
{
    #[ORM\ManyToOne(targetEntity: FormFieldInterface::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected FormFieldInterface $field;

    #[ORM\Column(length: 10)]
    protected string $locale;

    #[ORM\Column(length: 200)]
    protected string $label;

    #[ORM\Column(length: 200, nullable: true)]
    protected ?string $placeholder = null;

    /**
     * The choices of a select, checkbox or radio — translated, because a
     * reader picks from words rather than from keys.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    protected array $options = [];

    public function getField(): FormFieldInterface
    {
        return $this->field;
    }

    public function setField(FormFieldInterface $field): static
    {
        $this->field = $field;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

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

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function setPlaceholder(?string $placeholder): static
    {
        $this->placeholder = $placeholder;

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
}
