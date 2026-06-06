<?php

declare(strict_types=1);

namespace {{NAMESPACE}}\Setting;

use Aurora\Core\Module\Toggle\ModuleToggle;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * {{MODULE_LABEL}} module's own access toggles (one row each in core_settings,
 * group `modules`). Self-contained so the {{MODULE}} module declares its
 * toggles WITHOUT the central `ModuleParameterEnum` — each module owns its own
 * toggle definitions (monorepo-split). Sub-toggles are appended by
 * `/add-submodule`; a public-facing `Frontend` case is added when the module
 * has a FrontendDescriptor (see `--with-frontend`).
 *
 * Exposed to the toggle machinery via {@see {{MODULE}}Module} (getToggles) and
 * to the settings sync via {@see {{MODULE}}ModuleParameterProvider}.
 */
enum {{MODULE}}ModuleParameterEnum: string implements ApplicationParameterEnumInterface
{
    private const string GROUP = 'modules';

    case Backend = 'modules_{{MODULE_ID}}_backend';

    public function getKey(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Backend => 'backend.modules.{{MODULE_ID}}_backend',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Backend => 'backend.modules.{{MODULE_ID}}_backend_description',
        };
    }

    public function getDefaultValue(): string
    {
        return '1';
    }

    public function getType(): string
    {
        return 'bool';
    }

    public function getGroup(): string
    {
        return self::GROUP;
    }

    public function getPlaceholder(): ?string
    {
        return null;
    }

    /** Module identifier for the top-level toggle, null for sub-toggles. */
    private function getModuleId(): ?string
    {
        return self::Backend === $this ? '{{MODULE_ID}}' : null;
    }

    /** Cascade dependency (parent that must be ON), null for the top-level. */
    private function getCascadeRequires(): ?string
    {
        return self::Backend === $this ? null : self::Backend->value;
    }

    /** Structural parent for dashboard grouping, null for the top-level. */
    private function getDisplayParent(): ?string
    {
        return self::Backend === $this ? null : self::Backend->value;
    }

    public function toToggle(): ModuleToggle
    {
        return new ModuleToggle(
            key: $this->value,
            labelKey: $this->getLabel(),
            descriptionKey: $this->getDescription(),
            parentKey: $this->getCascadeRequires(),
            moduleId: $this->getModuleId(),
            displayParentKey: $this->getDisplayParent(),
        );
    }
}
