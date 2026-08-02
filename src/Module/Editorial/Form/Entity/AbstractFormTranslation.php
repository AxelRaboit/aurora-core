<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class AbstractFormTranslation implements FormTranslationInterface
{
    #[ORM\ManyToOne(targetEntity: FormInterface::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected FormInterface $form;

    #[ORM\Column(length: 10)]
    protected string $locale;

    #[ORM\Column(length: 200)]
    protected string $title;

    /** The public URL segment: `/{locale}/forms/{slug}`. */
    #[ORM\Column(length: 200)]
    protected string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    public function getForm(): FormInterface
    {
        return $this->form;
    }

    public function setForm(FormInterface $form): static
    {
        $this->form = $form;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }
}
