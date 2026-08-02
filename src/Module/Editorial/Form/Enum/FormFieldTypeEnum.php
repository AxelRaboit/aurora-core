<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Enum;

enum FormFieldTypeEnum: string
{
    case Text = 'text';
    case Email = 'email';
    case Textarea = 'textarea';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Radio = 'radio';
    case Number = 'number';
    case Date = 'date';
    case Tel = 'tel';

    public function labelKey(): string
    {
        return sprintf('backend.forms.field_types.%s', $this->value);
    }

    /** Types whose meaning comes from a list the builder supplies. */
    public function hasOptions(): bool
    {
        return match ($this) {
            self::Select, self::Checkbox, self::Radio => true,
            default => false,
        };
    }

    /** Types that hold several values at once. */
    public function isMultiValue(): bool
    {
        return self::Checkbox === $this;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
