<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Setting;

use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * Editorial's non-toggle settings - the human-readable reference prefixes.
 * Toggles live in the central ModuleParameterEnum instead; these are values
 * an admin types, not switches.
 */
enum EditorialSettingEnum: string implements ApplicationParameterEnumInterface
{
    case PostPrefix = 'suite_editorial_post_prefix';
    case TaxonomyTermPrefix = 'suite_editorial_taxonomy_term_prefix';
    case CommentPrefix = 'suite_editorial_comment_prefix';
    case FormPrefix = 'suite_editorial_form_prefix';
    case FormFieldPrefix = 'suite_editorial_form_field_prefix';
    case FormSubmissionPrefix = 'suite_editorial_form_submission_prefix';

    public function getKey(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PostPrefix => 'suite.parameters.editorial_post_prefix.label',
            self::TaxonomyTermPrefix => 'suite.parameters.editorial_taxonomy_term_prefix.label',
            self::CommentPrefix => 'suite.parameters.editorial_comment_prefix.label',
            self::FormPrefix => 'suite.parameters.editorial_form_prefix.label',
            self::FormFieldPrefix => 'suite.parameters.editorial_form_field_prefix.label',
            self::FormSubmissionPrefix => 'suite.parameters.editorial_form_submission_prefix.label',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PostPrefix => 'suite.parameters.editorial_post_prefix.description',
            self::TaxonomyTermPrefix => 'suite.parameters.editorial_taxonomy_term_prefix.description',
            self::CommentPrefix => 'suite.parameters.editorial_comment_prefix.description',
            self::FormPrefix => 'suite.parameters.editorial_form_prefix.description',
            self::FormFieldPrefix => 'suite.parameters.editorial_form_field_prefix.description',
            self::FormSubmissionPrefix => 'suite.parameters.editorial_form_submission_prefix.description',
        };
    }

    public function getDefaultValue(): string
    {
        return match ($this) {
            self::PostPrefix => SequencePrefixEnum::Post->value,
            self::TaxonomyTermPrefix => SequencePrefixEnum::TaxonomyTerm->value,
            self::CommentPrefix => SequencePrefixEnum::Comment->value,
            self::FormPrefix => SequencePrefixEnum::Form->value,
            self::FormFieldPrefix => SequencePrefixEnum::FormField->value,
            self::FormSubmissionPrefix => SequencePrefixEnum::FormSubmission->value,
        };
    }

    public function getType(): string
    {
        return 'string';
    }

    public function getGroup(): string
    {
        return 'sequences';
    }

    public function getPlaceholder(): ?string
    {
        return null;
    }

    public function getOffWarning(): ?string
    {
        return null;
    }
}
