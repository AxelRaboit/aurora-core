<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Configuration section - global app parameters (Settings) and visual
 * customization (Themes). Split from PlatformModule in Jalon 4 so admin
 * configuration is its own module - peer of Platform at the toggle,
 * permission, and nav-section levels.
 */
final readonly class ConfigurationModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function __construct(private ConfigurationContext $configurationContext) {}

    public function getId(): string
    {
        return 'configuration';
    }

    public function getPermissions(): array
    {
        return [
            new NavPermission('configuration.settings.manage'),
            new NavPermission('configuration.themes.manage'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->configurationContext->isBackendEnabled()) {
            return [];
        }

        $items = [];

        if ($this->configurationContext->isSettingsEnabled()) {
            $items[] = new NavItem('backend_configuration_settings', 'backend.nav.settings', 'settings', requiredPrivilege: 'configuration.settings.manage', descriptionKey: 'backend.nav.settings_description');
        }

        if ($this->configurationContext->isThemesEnabled()) {
            $items[] = new NavItem('backend_configuration_themes', 'backend.nav.themes', 'palette', requiredPrivilege: 'configuration.themes.manage', descriptionKey: 'backend.nav.themes_description');
        }

        if ([] === $items) {
            return [];
        }

        return [new NavSection('configuration', $items, priority: 25)];
    }

    public function getCatalogNavSections(): array
    {
        return [
            new NavSection('configuration', [
                new NavItem('backend_configuration_settings', 'backend.nav.settings', 'settings', requiredPrivilege: 'configuration.settings.manage', descriptionKey: 'backend.nav.settings_description'),
                new NavItem('backend_configuration_themes', 'backend.nav.themes', 'palette', requiredPrivilege: 'configuration.themes.manage', descriptionKey: 'backend.nav.themes_description'),
            ], priority: 25),
        ];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::ConfigurationBackend->toToggle(),
            ModuleParameterEnum::ConfigurationSettings->toToggle(),
            ModuleParameterEnum::ConfigurationThemes->toToggle(),
        ];
    }
}
