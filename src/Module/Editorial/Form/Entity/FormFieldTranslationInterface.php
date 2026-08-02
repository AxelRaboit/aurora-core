<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

interface FormFieldTranslationInterface
{
    public function getId(): ?int;

    public function getField(): FormFieldInterface;

    public function setField(FormFieldInterface $field): static;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    public function getPlaceholder(): ?string;

    public function setPlaceholder(?string $placeholder): static;

    /** @return list<string> */
    public function getOptions(): array;

    /** @param list<string> $options */
    public function setOptions(array $options): static;
}
