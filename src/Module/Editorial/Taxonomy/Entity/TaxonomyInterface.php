<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Entity;

use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Doctrine\Common\Collections\Collection;

interface TaxonomyInterface
{
    public function getId(): ?int;

    public function getSlug(): string;

    public function setSlug(string $slug): static;

    public function isHierarchical(): bool;

    public function setHierarchical(bool $hierarchical): static;

    public function isBuiltIn(): bool;

    public function setIsBuiltIn(bool $isBuiltIn): static;

    /** @return Collection<string, TaxonomyTranslationInterface> */
    public function getTranslations(): Collection;

    public function getTranslation(string $locale): ?TaxonomyTranslationInterface;

    public function translate(string $locale): TaxonomyTranslationInterface;

    /** @return Collection<int, TaxonomyTermInterface> */
    public function getTerms(): Collection;

    public function findTermById(int $termId): ?TaxonomyTermInterface;

    /** @return Collection<int, PostTypeInterface> */
    public function getPostTypes(): Collection;
}
