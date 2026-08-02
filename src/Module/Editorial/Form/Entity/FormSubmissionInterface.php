<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use DateTimeImmutable;

interface FormSubmissionInterface
{
    public function getId(): ?int;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getForm(): FormInterface;

    public function setForm(FormInterface $form): static;

    /** @return array<string, string|list<string>> field id → what was filled in */
    public function getData(): array;

    /** @param array<string, string|list<string>> $data */
    public function setData(array $data): static;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getSubmittedAt(): DateTimeImmutable;

    public function getIp(): ?string;

    public function setIp(?string $ip): static;
}
