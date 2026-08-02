<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Entity;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Doctrine\Common\Collections\Collection;

interface TaxonomyTermInterface
{
    public function getId(): ?int;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getTaxonomy(): TaxonomyInterface;

    public function setTaxonomy(TaxonomyInterface $taxonomy): static;

    public function getParent(): ?TaxonomyTermInterface;

    public function setParent(?TaxonomyTermInterface $parent): static;

    /** @return Collection<int, TaxonomyTermInterface> */
    public function getChildren(): Collection;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    /** @return Collection<string, TaxonomyTermTranslationInterface> */
    public function getTranslations(): Collection;

    public function getTranslation(string $locale): ?TaxonomyTermTranslationInterface;

    public function translate(string $locale): TaxonomyTermTranslationInterface;

    /** @return Collection<int, PostInterface> */
    public function getPosts(): Collection;

    /**
     * Inverse side — Post owns the association. Keeps the loaded graph
     * consistent so a response serialized right after a write is accurate.
     */
    public function addPost(PostInterface $post): static;

    public function removePost(PostInterface $post): static;

    /** @return list<TaxonomyTermInterface> */
    public function getAncestors(): array;

    public function isDescendantOf(TaxonomyTermInterface $candidate): bool;
}
