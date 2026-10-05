<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Enum;

interface ApplicationParameterEnumInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    public function getDescription(): string;

    public function getDefaultValue(): string;

    public function getType(): string;

    public function getGroup(): string;

    /**
     * Translation key for an optional placeholder example shown inside the
     * settings input (e.g. `'suite.parameters.site_name.placeholder'` →
     * "Mon site"). Return `null` when no useful example exists and the
     * description below the field is enough.
     */
    public function getPlaceholder(): ?string;

    /** Shown before a bool parameter is switched off, when it needs saying. */
    public function getOffWarning(): ?string;
}
