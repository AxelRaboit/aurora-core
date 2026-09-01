<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Entity;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Doctrine\Common\Collections\Collection;

interface PostTypeInterface
{
    public function getId(): ?int;

    public function getSlug(): string;

    public function setSlug(string $slug): static;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    /** What this type of content is for, in the author's own words, or null. */
    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    public function getIcon(): ?string;

    public function setIcon(?string $icon): static;

    public function hasArchive(): bool;

    public function setHasArchive(bool $hasArchive): static;

    public function isBuiltIn(): bool;

    public function setIsBuiltIn(bool $isBuiltIn): static;

    /** @return list<string> */
    public function getSupports(): array;

    /** @param list<string> $supports */
    public function setSupports(array $supports): static;

    public function supports(string $feature): bool;

    /** @return Collection<int, PostTypeFieldInterface> */
    public function getFields(): Collection;

    public function findFieldById(int $fieldId): ?PostTypeFieldInterface;

    public function addField(PostTypeFieldInterface $field): static;

    public function removeField(PostTypeFieldInterface $field): static;

    /** @return Collection<int, PostInterface> */
    public function getPosts(): Collection;

    /** @return Collection<int, TaxonomyInterface> */
    public function getTaxonomies(): Collection;

    public function addTaxonomy(TaxonomyInterface $taxonomy): static;

    public function removeTaxonomy(TaxonomyInterface $taxonomy): static;
}
