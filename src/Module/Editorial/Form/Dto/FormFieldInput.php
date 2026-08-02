<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Dto;

use Aurora\Module\Editorial\Form\Enum\ConditionLogicEnum;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class FormFieldInput implements FormFieldInputInterface
{
    /**
     * @param array<string, array{label: string, placeholder: ?string, options: list<string>}> $translations
     * @param list<array{fieldId: int, value: string}>                                         $conditions
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'backend.forms.errors.field_translations_required')]
        public readonly array $translations,
        public readonly FormFieldTypeEnum $type = FormFieldTypeEnum::Text,
        public readonly bool $required = false,
        public readonly array $conditions = [],
        public readonly ConditionLogicEnum $conditionsLogic = ConditionLogicEnum::All,
        public readonly ?int $step = null,
    ) {}

    /**
     * A select with nothing to select from renders as an empty dropdown that
     * a required field then refuses to accept — a form no visitor can submit,
     * and nothing in the builder says why.
     */
    #[Assert\Callback]
    public function validateOptions(ExecutionContextInterface $context): void
    {
        if (!$this->type->hasOptions()) {
            return;
        }

        foreach ($this->translations as $locale => $translation) {
            if ([] === $translation['options']) {
                $context->buildViolation('backend.forms.errors.options_required')
                    ->atPath(sprintf('translations[%s].options', $locale))
                    ->addViolation();
            }
        }
    }

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function getType(): FormFieldTypeEnum
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getConditionsLogic(): ConditionLogicEnum
    {
        return $this->conditionsLogic;
    }

    public function getStep(): ?int
    {
        return $this->step;
    }
}
