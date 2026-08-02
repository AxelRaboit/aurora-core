<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

interface FormTranslationInterface
{
    public function getId(): ?int;

    public function getForm(): FormInterface;

    public function setForm(FormInterface $form): static;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getTitle(): string;

    public function setTitle(string $title): static;

    public function getSlug(): string;

    public function setSlug(string $slug): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;
}
