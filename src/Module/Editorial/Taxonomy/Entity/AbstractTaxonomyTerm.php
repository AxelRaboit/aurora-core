<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractTaxonomyTerm implements TaxonomyTermInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    protected ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: TaxonomyInterface::class, inversedBy: 'terms')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected TaxonomyInterface $taxonomy;

    #[ORM\ManyToOne(targetEntity: TaxonomyTermInterface::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?TaxonomyTermInterface $parent = null;

    /** @var Collection<int, TaxonomyTermInterface> */
    #[ORM\OneToMany(targetEntity: TaxonomyTermInterface::class, mappedBy: 'parent')]
    protected Collection $children;

    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    /** @var Collection<string, TaxonomyTermTranslationInterface> */
    #[ORM\OneToMany(targetEntity: TaxonomyTermTranslationInterface::class, mappedBy: 'term', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    protected Collection $translations;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getTaxonomy(): TaxonomyInterface
    {
        return $this->taxonomy;
    }

    public function setTaxonomy(TaxonomyInterface $taxonomy): static
    {
        $this->taxonomy = $taxonomy;

        return $this;
    }

    public function getParent(): ?TaxonomyTermInterface
    {
        return $this->parent;
    }

    public function setParent(?TaxonomyTermInterface $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getChildren(): Collection
    {
        return $this->children;
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

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function getTranslation(string $locale): ?TaxonomyTermTranslationInterface
    {
        return $this->translations->get($locale);
    }

    public function translate(string $locale): TaxonomyTermTranslationInterface
    {
        if ($this->translations->containsKey($locale)) {
            return $this->translations->get($locale);
        }

        $translation = $this->createTranslation();
        $translation->setTerm($this);
        $translation->setLocale($locale);

        $this->translations->set($locale, $translation);

        return $translation;
    }

    public function getAncestors(): array
    {
        $ancestors = [];
        $current = $this->parent;
        while ($current instanceof TaxonomyTermInterface) {
            array_unshift($ancestors, $current);
            $current = $current->getParent();
        }

        return $ancestors;
    }

    /**
     * Guards the move operation against making a term its own descendant.
     * Compares ids as well as identity: an ancestor walked through a
     * ManyToOne may be a Doctrine proxy while the candidate is the loaded
     * entity, and `===` says no to that pair.
     */
    public function isDescendantOf(TaxonomyTermInterface $candidate): bool
    {
        $candidateId = $candidate->getId();

        foreach ($this->getAncestors() as $ancestor) {
            if ($ancestor === $candidate) {
                return true;
            }

            if (null !== $candidateId && $ancestor->getId() === $candidateId) {
                return true;
            }
        }

        return false;
    }

    /** @see AbstractTaxonomy::createTranslation() for why this hook exists. */
    protected function createTranslation(): TaxonomyTermTranslationInterface
    {
        return new TaxonomyTermTranslation();
    }
}
