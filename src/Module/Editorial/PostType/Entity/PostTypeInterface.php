<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Entity;

use Doctrine\Common\Collections\Collection;

interface PostTypeInterface
{
    public function getId(): ?int;

    public function getSlug(): string;

    public function setSlug(string $slug): static;

    public function getLabel(): string;

    public function setLabel(string $label): static;

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
}
