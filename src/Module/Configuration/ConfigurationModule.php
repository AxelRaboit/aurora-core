<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleNavViewProviderInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\ModuleNavGroup;
use Aurora\Core\Module\Nav\ModuleNavView;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Configuration\SettingsTabAccess;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Configuration section - global app parameters (Settings) and visual
 * customization (Themes). Split from PlatformModule in Jalon 4 so admin
 * configuration is its own module - peer of Platform at the toggle,
 * permission, and nav-section levels.
 */
final readonly class ConfigurationModule implements ModuleInterface, ModuleNavViewProviderInterface, ModuleToggleProviderInterface
{
    /**
     * Icon per settings tab, by the kebab names `ICON_MAP` already resolves.
     * A tab absent from here - a client's own - falls back to the sliders glyph
     * rather than to the generic document one, which would read as a page of
     * content rather than a page of knobs.
     */
    private const array TAB_ICONS = [
        'general' => 'settings',
        'reading' => 'file-text',
        'localization' => 'globe-2',
        'branding' => 'image',
        'appearance' => 'palette',
        'seo' => 'trending-up',
        'system' => 'gauge',
        'email' => 'mail',
        'media' => 'images',
        'sequences' => 'scan-line',
        'navigation' => 'menu',
    ];

    public function __construct(
        private ConfigurationContext $configurationContext,
        private SettingsTabAccess $tabAccess,
    ) {}

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
        if (!$this->configurationContext->isSuiteEnabled()) {
            return [];
        }

        $items = [];

        if ($this->configurationContext->isSettingsEnabled()) {
            $items[] = new NavItem('suite_configuration_settings', 'suite.nav.settings', 'settings', requiredPrivilege: 'configuration.settings.manage', descriptionKey: 'suite.nav.settings_description');
        }

        if ($this->configurationContext->isThemesEnabled()) {
            $items[] = new NavItem('suite_configuration_themes', 'suite.nav.themes', 'palette', requiredPrivilege: 'configuration.themes.manage', descriptionKey: 'suite.nav.themes_description');
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
                new NavItem('suite_configuration_settings', 'suite.nav.settings', 'settings', requiredPrivilege: 'configuration.settings.manage', descriptionKey: 'suite.nav.settings_description'),
                new NavItem('suite_configuration_themes', 'suite.nav.themes', 'palette', requiredPrivilege: 'configuration.themes.manage', descriptionKey: 'suite.nav.themes_description'),
            ], priority: 25),
        ];
    }

    /**
     * Configuration's own menu: every settings tab as a destination, plus
     * Themes.
     *
     * The tabs were a `w-44` column inside the page with its state in the URL
     * fragment. Eleven destinations that the side menu could not show, the
     * breadcrumb could not name and the search palette could not find - a
     * fragment is not a route. Here they are `NavItem`s like any other.
     *
     * Only reached for a route this module owns (see `ModuleNavResolver`), so
     * reading the contributed tabs here does not cost anything on the pages of
     * other modules. `SettingDefinitionRegistry::getTabs()` memoises within the
     * request, and the settings page resolves them anyway.
     */
    public function getModuleNavView(): ?ModuleNavView
    {
        if (!$this->configurationContext->isSuiteEnabled()) {
            return null;
        }

        $groups = [];

        $themes = $this->configurationContext->isThemesEnabled() ? $this->themesNavItem() : null;

        if ($this->configurationContext->isSettingsEnabled()) {
            $items = [];

            foreach ($this->tabAccess->visibleTabs() as $tab) {
                $items[] = new NavItem(
                    route: 'suite_configuration_settings_tab',
                    labelKey: sprintf('suite.settings.tabs.%s', $tab->id),
                    icon: self::TAB_ICONS[$tab->id] ?? 'sliders-horizontal',
                    requiredPrivilege: 'configuration.settings.manage',
                    descriptionKey: sprintf('suite.settings.tabs.%s_description', $tab->id),
                    routeParameters: ['tab' => $tab->id],
                    // Eleven entries share one route name, so the route name
                    // cannot be the stable key: hiding one tab from the menu
                    // would hide all eleven, and the active row would be all of
                    // them at once.
                    key: sprintf('configuration.settings.tab.%s', $tab->id),
                );

                // Themes inside the Réglages section, right after Apparence,
                // the other entry about colours. It sat in a second group
                // with no header: drawn under the tabs it looked like one of
                // them, yet stayed in view when Réglages was folded (Axel,
                // 10/10/2026).
                if ('appearance' === $tab->id && $themes instanceof NavItem) {
                    $items[] = $themes;
                    $themes = null;
                }
            }

            // No Apparence tab for this reader: the end of the section.
            if ($themes instanceof NavItem && [] !== $items) {
                $items[] = $themes;
                $themes = null;
            }

            if ([] !== $items) {
                $groups[] = new ModuleNavGroup('settings', $items, labelKey: 'suite.nav.settings');
            }
        }

        // Settings switched off: themes alone, in a group of their own.
        if ($themes instanceof NavItem) {
            $groups[] = new ModuleNavGroup('appearance', [$themes]);
        }

        if ([] === $groups) {
            return null;
        }

        return new ModuleNavView('configuration', $groups);
    }

    private function themesNavItem(): NavItem
    {
        return new NavItem(
            'suite_configuration_themes',
            'suite.nav.themes',
            'palette',
            requiredPrivilege: 'configuration.themes.manage',
            descriptionKey: 'suite.nav.themes_description',
        );
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::ConfigurationSuite->toToggle(),
            ModuleParameterEnum::ConfigurationSettings->toToggle(),
            ModuleParameterEnum::ConfigurationThemes->toToggle(),
        ];
    }
}
