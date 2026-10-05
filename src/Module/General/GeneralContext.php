<?php

declare(strict_types=1);

namespace Aurora\Module\General;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Toggle façade for the "Général" suite section (Dashboard).
 * Mirrors the {@see PlatformContext} pattern.
 *
 * When the Dashboard is masked (globally or per-user),
 * {@see GeneralRouteGateSubscriber}
 * redirects any hit on `suite_dashboard` to `suite_general_profile`
 * so the user always lands on something they can read instead of
 * seeing a 404.
 */
final readonly class GeneralContext
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isSuiteEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::GeneralSuite);
    }

    public function isDashboardEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::GeneralDashboard);
    }
}
