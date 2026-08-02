<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Dto;

use Aurora\Module\Editorial\Form\Enum\ConditionLogicEnum;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;

interface FormFieldInputInterface
{
    /** @return array<string, array{label: string, placeholder: ?string, options: list<string>}> */
    public function getTranslations(): array;

    public function getType(): FormFieldTypeEnum;

    public function isRequired(): bool;

    /** @return list<array{fieldId: int, value: string}> */
    public function getConditions(): array;

    public function getConditionsLogic(): ConditionLogicEnum;

    public function getStep(): ?int;
}
