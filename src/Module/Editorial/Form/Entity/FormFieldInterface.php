<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use Aurora\Module\Editorial\Form\Enum\ConditionLogicEnum;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Doctrine\Common\Collections\Collection;

interface FormFieldInterface
{
    public function getId(): ?int;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getForm(): FormInterface;

    public function setForm(FormInterface $form): static;

    public function getType(): FormFieldTypeEnum;

    public function setType(FormFieldTypeEnum $type): static;

    public function isRequired(): bool;

    public function setRequired(bool $required): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    /** @return list<array{fieldId: int, value: string}> */
    public function getConditions(): array;

    /** @param list<array{fieldId: int, value: string}> $conditions */
    public function setConditions(array $conditions): static;

    public function getConditionsLogic(): ConditionLogicEnum;

    public function setConditionsLogic(ConditionLogicEnum $logic): static;

    public function getStep(): ?int;

    public function setStep(?int $step): static;

    /** @return Collection<string, FormFieldTranslationInterface> */
    public function getTranslations(): Collection;

    public function getTranslation(string $locale): ?FormFieldTranslationInterface;

    public function translate(string $locale): FormFieldTranslationInterface;
}
