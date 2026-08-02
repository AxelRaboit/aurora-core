<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Entity;

interface PostTypeFieldInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function setName(string $name): static;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    public function getType(): string;

    public function setType(string $type): static;

    public function isRequired(): bool;

    public function setRequired(bool $required): static;

    public function isTranslatable(): bool;

    public function setTranslatable(bool $translatable): static;

    /** @return array<string, mixed> */
    public function getOptions(): array;

    /** @param array<string, mixed> $options */
    public function setOptions(array $options): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function getPostType(): PostTypeInterface;

    public function setPostType(PostTypeInterface $postType): static;
}
