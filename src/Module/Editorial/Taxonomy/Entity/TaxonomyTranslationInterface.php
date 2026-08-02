<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Entity;

interface TaxonomyTranslationInterface
{
    public function getId(): ?int;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    public function getTaxonomy(): TaxonomyInterface;

    public function setTaxonomy(TaxonomyInterface $taxonomy): static;
}
