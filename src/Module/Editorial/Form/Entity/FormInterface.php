<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface FormInterface
{
    public function getId(): ?int;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getNotifyEmail(): ?string;

    public function setNotifyEmail(?string $notifyEmail): static;

    public function getWebhookUrl(): ?string;

    public function setWebhookUrl(?string $webhookUrl): static;

    public function isCrmSync(): bool;

    public function setCrmSync(bool $crmSync): static;

    /** @return list<array{title: string}>|null */
    public function getSteps(): ?array;

    /** @param list<array{title: string}>|null $steps */
    public function setSteps(?array $steps): static;

    public function isActive(): bool;

    public function setActive(bool $active): static;

    /** @return Collection<string, FormTranslationInterface> */
    public function getTranslations(): Collection;

    public function getTranslation(string $locale): ?FormTranslationInterface;

    public function translate(string $locale): FormTranslationInterface;

    /** @return Collection<int, FormFieldInterface> */
    public function getFields(): Collection;

    public function addField(FormFieldInterface $field): static;

    public function removeField(FormFieldInterface $field): static;

    public function findFieldById(int $fieldId): ?FormFieldInterface;

    /** @return Collection<int, FormSubmissionInterface> */
    public function getSubmissions(): Collection;

    public function getCreatedAt(): DateTimeImmutable;

    public function getUpdatedAt(): DateTimeImmutable;

    public function touch(): static;
}
