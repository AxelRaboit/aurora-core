<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Editorial\Form\Enum\ConditionLogicEnum;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(FormFieldInputFactoryInterface::class)]
class FormFieldInputFactory implements FormFieldInputFactoryInterface
{
    public function __construct(protected readonly TranslatorInterface $translator) {}

    /** @param array<string, mixed> $data */
    public function fromArray(array $data): FormFieldInputInterface
    {
        $step = (int) ($data['step'] ?? 0);

        return new FormFieldInput(
            translations: $this->translations($data['translations'] ?? null),
            type: $this->type($data['type'] ?? null),
            required: (bool) ($data['required'] ?? false),
            conditions: $this->conditions($data['conditions'] ?? null),
            conditionsLogic: $this->logic($data['conditionsLogic'] ?? null),
            step: $step > 0 ? $step : null,
        );
    }

    /** @return array<string, array{label: string, placeholder: ?string, options: list<string>}> */
    private function translations(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $translations = [];
        foreach ($raw as $locale => $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $label = Str::trimOrNull((string) ($payload['label'] ?? ''));
            if (null === $label) {
                continue;
            }

            $translations[(string) $locale] = [
                'label' => $label,
                'placeholder' => Str::trimOrNull((string) ($payload['placeholder'] ?? '')),
                'options' => $this->options($payload['options'] ?? null),
            ];
        }

        return $translations;
    }

    /** @return list<string> */
    private function options(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $options = [];
        foreach ($raw as $option) {
            $value = mb_trim((string) $option);
            if ('' !== $value) {
                $options[] = $value;
            }
        }

        // Duplicates make two choices a visitor cannot tell apart, and a
        // stored answer nobody can trace back to one of them.
        return array_values(array_unique($options));
    }

    /**
     * Conditions naming a field that no longer exists are dropped rather than
     * rejected: deleting a field should not make every field that referred to
     * it unsavable.
     *
     * @return list<array{fieldId: int, value: string}>
     */
    private function conditions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $conditions = [];
        foreach ($raw as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $fieldId = (int) ($condition['fieldId'] ?? 0);
            if ($fieldId <= 0) {
                continue;
            }

            $conditions[] = ['fieldId' => $fieldId, 'value' => (string) ($condition['value'] ?? '')];
        }

        return $conditions;
    }

    private function type(mixed $raw): FormFieldTypeEnum
    {
        if (!is_string($raw) || '' === $raw) {
            return FormFieldTypeEnum::Text;
        }

        return FormFieldTypeEnum::tryFrom($raw)
            ?? throw new InvalidArgumentException($this->translator->trans('backend.forms.errors.field_type_invalid'));
    }

    private function logic(mixed $raw): ConditionLogicEnum
    {
        if (!is_string($raw) || '' === $raw) {
            return ConditionLogicEnum::All;
        }

        return ConditionLogicEnum::tryFrom($raw)
            ?? throw new InvalidArgumentException($this->translator->trans('backend.forms.errors.condition_logic_invalid'));
    }
}
