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

    /**
     * Whether the calendar is on for the installation, whoever is signed in.
     *
     * What the sync asks. A date announced while somebody who masked the
     * calendar for themselves was saving a post used to be dropped - the
     * calendar exists for everybody else, and it went out of date because of
     * one person's menu preference.
     */
    public function isGloballyEnabled(): bool
    {
        return $this->moduleAccessChecker->isGloballyEnabled(ModuleParameterEnum::PlanningBackend);
    }
}
