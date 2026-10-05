<?php

declare(strict_types=1);

namespace Aurora\Module\Platform;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Toggle façade for the "Platform" section of the suite (Users - the
 * organization layer). Media moved to {@see MediaContext} in Jalon 4.5
 * since it's cross-cutting infrastructure used by every module.
 * Configuration (Settings + Themes) lives in {@see ConfigurationContext}
 * since the earlier Jalon 4 split.
 */
final readonly class PlatformContext
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isSuiteEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::PlatformSuite);
    }

    public function isUsersEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::PlatformUsers);
    }
}
