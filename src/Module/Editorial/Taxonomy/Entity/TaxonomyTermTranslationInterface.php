<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Entity;

interface TaxonomyTermTranslationInterface
{
    public function getId(): ?int;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getName(): string;

    public function setName(string $name): static;

    public function getSlug(): string;

    public function setSlug(string $slug): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    public function getTerm(): TaxonomyTermInterface;

    public function setTerm(TaxonomyTermInterface $term): static;
}
