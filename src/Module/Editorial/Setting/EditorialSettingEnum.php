<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Setting;

use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * Editorial's non-toggle settings — the human-readable reference prefixes.
 * Toggles live in the central ModuleParameterEnum instead; these are values
 * an admin types, not switches.
 */
enum EditorialSettingEnum: string implements ApplicationParameterEnumInterface
{
    case PostPrefix = 'backend_editorial_post_prefix';
    case TaxonomyTermPrefix = 'backend_editorial_taxonomy_term_prefix';

    public function getKey(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PostPrefix => 'backend.parameters.editorial_post_prefix.label',
            self::TaxonomyTermPrefix => 'backend.parameters.editorial_taxonomy_term_prefix.label',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PostPrefix => 'backend.parameters.editorial_post_prefix.description',
            self::TaxonomyTermPrefix => 'backend.parameters.editorial_taxonomy_term_prefix.description',
        };
    }

    public function getDefaultValue(): string
    {
        return match ($this) {
            self::PostPrefix => SequencePrefixEnum::Post->value,
            self::TaxonomyTermPrefix => SequencePrefixEnum::TaxonomyTerm->value,
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
}
