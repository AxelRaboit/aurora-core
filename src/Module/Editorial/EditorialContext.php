<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Reads Editorial's module toggles. Everything in the module asks this
 * rather than {@see ModuleAccessChecker} directly, so a toggle is named
 * once and the callers read as intent ("are posts enabled?") instead of
 * as a settings lookup.
 *
 * One method per toggle; they arrive as the sub-modules they gate do.
 */
final readonly class EditorialContext
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isBackendEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::EditorialBackend);
    }
}
