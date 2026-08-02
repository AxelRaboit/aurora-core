<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The label column is `name` here while a taxonomy's own translation calls
 * it `label`. Kept as-is: both tables already exist under those names, and
 * a rename would cost a migration for no behaviour.
 */
#[ORM\MappedSuperclass]
abstract class AbstractTaxonomyTermTranslation implements TaxonomyTermTranslationInterface
{
    #[ORM\Column(length: 10)]
    protected string $locale;

    #[ORM\Column(length: 150)]
    protected string $name;

    /** Per-locale, so the same term can read /fr/etiquette/... and /en/tag/... */
    #[ORM\Column(length: 180)]
    protected string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    #[ORM\ManyToOne(targetEntity: TaxonomyTermInterface::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected TaxonomyTermInterface $term;

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
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

    public function getTerm(): TaxonomyTermInterface
    {
        return $this->term;
    }

    public function setTerm(TaxonomyTermInterface $term): static
    {
        $this->term = $term;

        return $this;
    }
}
