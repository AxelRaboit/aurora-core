<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Setting;

use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

enum GedSettingEnum: string implements ApplicationParameterEnumInterface
{
    case DocumentPrefix = 'backend_ged_document_prefix';

    public function getKey(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DocumentPrefix => 'backend.parameters.ged_document_prefix.label',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::DocumentPrefix => 'backend.parameters.ged_document_prefix.description',
        };
    }

    public function getDefaultValue(): string
    {
        return match ($this) {
            self::DocumentPrefix => SequencePrefixEnum::GedDocument->value,
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

    /**
     * No placeholder by default - override on a per-case basis when an
     * example value is genuinely clearer than the description alone.
     */
    public function getPlaceholder(): ?string
    {
        return null;
    }
}
