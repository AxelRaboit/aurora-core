<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Enum;

/**
 * What a new form starts with.
 *
 * Everyone writes the same contact form, and an empty builder is the hardest
 * screen to start from: a template lays the usual questions down, already
 * worded in every language of the site, and the author removes what does not
 * fit. Nothing ties the form to its template afterwards - it is a starting
 * point, not a type.
 *
 * The questions are named by key and worded in the translations
 * (`backend.forms.template_fields.{key}`), so a template reads right in each
 * language without a second copy of this list.
 */
enum FormTemplateEnum: string
{
    case Blank = 'blank';
    case Contact = 'contact';
    case Quote = 'quote';
    case Event = 'event';

    public function labelKey(): string
    {
        return sprintf('backend.forms.templates.%s.label', $this->value);
    }

    public function descriptionKey(): string
    {
        return sprintf('backend.forms.templates.%s.description', $this->value);
    }

    /**
     * The steps, by key, or none for a single page.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        return match ($this) {
            self::Quote => ['you', 'project'],
            default => [],
        };
    }

    /**
     * The questions, in order. `step` counts from 1 and only means something
     * when the template has steps.
     *
     * @return list<array{key: string, type: FormFieldTypeEnum, required: bool, step: int|null, placeholder: bool, options: list<string>}>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Blank => [],
            self::Contact => [
                self::field('name', FormFieldTypeEnum::Text, required: true),
                self::field('email', FormFieldTypeEnum::Email, required: true),
                self::field('phone', FormFieldTypeEnum::Tel),
                self::field('message', FormFieldTypeEnum::Textarea, required: true),
            ],
            self::Quote => [
                self::field('name', FormFieldTypeEnum::Text, required: true, step: 1),
                self::field('email', FormFieldTypeEnum::Email, required: true, step: 1),
                self::field('phone', FormFieldTypeEnum::Tel, step: 1),
                self::field('need', FormFieldTypeEnum::Textarea, required: true, step: 2),
                self::field('budget', FormFieldTypeEnum::Select, step: 2, options: ['small', 'medium', 'large', 'unknown']),
                self::field('deadline', FormFieldTypeEnum::Date, step: 2),
            ],
            self::Event => [
                self::field('name', FormFieldTypeEnum::Text, required: true),
                self::field('email', FormFieldTypeEnum::Email, required: true),
                self::field('guests', FormFieldTypeEnum::Number, required: true),
                self::field('remarks', FormFieldTypeEnum::Textarea),
            ],
        };
    }

    /**
     * @param list<string> $options
     *
     * @return array{key: string, type: FormFieldTypeEnum, required: bool, step: int|null, placeholder: bool, options: list<string>}
     */
    private static function field(
        string $key,
        FormFieldTypeEnum $type,
        bool $required = false,
        ?int $step = null,
        array $options = [],
    ): array {
        // A choice list opens on its placeholder, which says « choose »; the
        // other types show an example of what to type.
        return ['key' => $key, 'type' => $type, 'required' => $required, 'step' => $step, 'placeholder' => FormFieldTypeEnum::Date !== $type, 'options' => $options];
    }
}
