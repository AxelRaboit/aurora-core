<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Toggle façade for the "Configuration" section of the suite (Settings,
 * Themes). Sibling of {@see PlatformContext}; split from it in Jalon 4 so
 * admin-config tooling is its own module - peer of Platform at the
 * toggle/permission/nav-section levels - instead of being a sub-tab of it.
 */
final readonly class ConfigurationContext
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isSuiteEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::ConfigurationSuite);
    }

    public function isSettingsEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::ConfigurationSettings);
    }

    public function isThemesEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::ConfigurationThemes);
    }
}
