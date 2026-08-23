<?php

declare(strict_types=1);

namespace Aurora\Module\Planning;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Reads the calendar's toggle. Everything in the module asks this rather than
 * {@see ModuleAccessChecker} directly, so the toggle is named once and callers
 * read as intent instead of as a settings lookup.
 */
final readonly class PlanningContext
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isBackendEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::PlanningBackend);
    }
}
