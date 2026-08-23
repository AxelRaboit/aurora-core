<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Service;

use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;

/**
 * Checks a submission against the form that produced it.
 *
 * Only the fields the answers actually make visible are considered - for
 * both halves. Validating a hidden field's "required" makes the form
 * unsubmittable; keeping a hidden field's value stores an answer to a
 * question the visitor was never shown.
 *
 * Messages are translation keys. The reference returned Symfony's own
 * defaults, so a French visitor was told "This value should not be blank."
 * on a public page.
 */
final readonly class FormSubmissionValidator
{
    private const string ERROR_PREFIX = 'frontend.editorial.forms.errors.';

    public function __construct(private FormConditionEvaluator $conditionEvaluator) {}

    /**
     * @param array<array-key, mixed> $answers keyed by field id
     *
     * @return array<string, string> field id → message key
     */
    public function validate(FormInterface $form, array $answers): array
    {
        $errors = [];

        foreach ($this->conditionEvaluator->visibleFields($form, $answers) as $field) {
            $error = $this->checkField($field, $answers[(int) $field->getId()] ?? null);
            if (null !== $error) {
                $errors[(string) $field->getId()] = self::ERROR_PREFIX.$error;
            }
        }

        return $errors;
    }

    /**
     * What gets stored: the visible fields only, normalised to strings.
     *
     * @param array<array-key, mixed> $answers
     *
     * @return array<string, string|list<string>>
     */
    public function extract(FormInterface $form, array $answers): array
    {
        $data = [];

        foreach ($this->conditionEvaluator->visibleFields($form, $answers) as $field) {
            $value = $answers[(int) $field->getId()] ?? null;
            if (null === $value) {
                continue;
            }

            $data[(string) $field->getId()] = is_array($value)
                ? array_values(array_map(strval(...), $value))
                : (string) $value;
        }

        return $data;
    }

    /** @return string|null the error suffix, or null when the value is acceptable */
    private function checkField(FormFieldInterface $field, mixed $value): ?string
    {
        if ($this->isEmpty($value)) {
            return $field->isRequired() ? 'required' : null;
        }

        return match ($field->getType()) {
            FormFieldTypeEnum::Email => $this->checkEmail($value),
            FormFieldTypeEnum::Number => is_numeric($value) ? null : 'number_invalid',
            FormFieldTypeEnum::Date => $this->checkDate((string) $value),
            FormFieldTypeEnum::Select, FormFieldTypeEnum::Radio, FormFieldTypeEnum::Checkbox => $this->checkOptions($field, $value),
            default => null,
        };
    }

    private function checkEmail(mixed $value): ?string
    {
        return is_string($value) && false !== filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'email_invalid';
    }

    private function checkDate(string $value): ?string
    {
        // The shape a date input sends. Anything else was not typed into one.
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && false !== strtotime($value)
            ? null
            : 'date_invalid';
    }

    /**
     * A choice has to be one of the offered ones. Without this a visitor can
     * post any string for a select, and it is stored, emailed and sent to the
     * webhook as though the form had offered it.
     */
    private function checkOptions(FormFieldInterface $field, mixed $value): ?string
    {
        $offered = $this->offeredOptions($field);
        if ([] === $offered) {
            return null;
        }

        $chosen = is_array($value) ? array_map(strval(...), $value) : [(string) $value];

        foreach ($chosen as $one) {
            if (!in_array($one, $offered, true)) {
                return 'choice_invalid';
            }
        }

        return null;
    }

    /**
     * Every locale's options count as offered: a visitor reading the French
     * page picks French words, and the same field's English list is just as
     * legitimate an answer from the English page.
     *
     * @return list<string>
     */
    private function offeredOptions(FormFieldInterface $field): array
    {
        $offered = [];
        foreach ($field->getTranslations() as $translation) {
            foreach ($translation->getOptions() as $option) {
                $offered[] = $option;
            }
        }

        return array_values(array_unique($offered));
    }

    private function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return [] === $value;
        }

        return null === $value || '' === mb_trim((string) $value);
    }
}
