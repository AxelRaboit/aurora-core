<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Service;

use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;

/**
 * Turns a submission back into question-and-answer pairs.
 *
 * Submissions are stored by field id, so every consumer - the email, the
 * export, the webhook, the admin screen - has to look the label up again.
 * Doing it once here is what keeps them agreeing about what a submission
 * said.
 */
final readonly class FormFieldLabeler
{
    /** @return list<array{label: string, value: string}> */
    public function pairs(FormInterface $form, FormSubmissionInterface $submission, string $locale): array
    {
        $data = $submission->getData();

        $pairs = [];
        foreach ($form->getFields() as $field) {
            $key = (string) $field->getId();
            if (!array_key_exists($key, $data)) {
                // A field the visitor never saw, because its conditions were
                // unmet. Listing it with an empty answer would read as "they
                // left it blank", which is a different thing.
                continue;
            }

            $value = $data[$key];

            $pairs[] = [
                'label' => $this->label($field, $locale),
                'value' => is_array($value) ? implode(', ', $value) : $value,
            ];
        }

        return $pairs;
    }

    /**
     * The first non-blank answer to a field of this type, in field order: the
     * address of an email field, the name of the first text field.
     */
    public function firstAnswerOfType(FormInterface $form, FormSubmissionInterface $submission, FormFieldTypeEnum $type): ?string
    {
        $data = $submission->getData();

        foreach ($form->getFields() as $field) {
            if ($field->getType() !== $type) {
                continue;
            }

            $value = $data[(string) $field->getId()] ?? null;
            if (is_string($value) && '' !== mb_trim($value)) {
                return mb_trim($value);
            }
        }

        return null;
    }

    public function label(FormFieldInterface $field, string $locale): string
    {
        $translation = $field->getTranslation($locale) ?? ($field->getTranslations()->first() ?: null);

        return $translation?->getLabel() ?? '#'.$field->getId();
    }

    public function title(FormInterface $form, string $locale): string
    {
        $translation = $form->getTranslation($locale) ?? ($form->getTranslations()->first() ?: null);

        return $translation?->getTitle() ?? '#'.$form->getId();
    }

    public function slug(FormInterface $form, string $locale): string
    {
        $translation = $form->getTranslation($locale) ?? ($form->getTranslations()->first() ?: null);

        return $translation?->getSlug() ?? (string) $form->getId();
    }
}
