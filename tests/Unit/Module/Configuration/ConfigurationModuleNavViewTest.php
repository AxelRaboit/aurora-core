<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration;

use Aurora\Core\Module\Nav\ModuleNavGroup;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\ConfigurationContext;
use Aurora\Module\Configuration\ConfigurationModule;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;
use Aurora\Module\Configuration\Setting\Configuration\SettingDefinitionRegistry;
use Aurora\Module\Configuration\Setting\Configuration\SettingsTabAccess;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Configuration is the first module to declare its own menu.
 *
 * The settings tabs were a `w-44` column inside the page with their state in the
 * URL fragment: eleven destinations the side menu could not show, the breadcrumb
 * could not name and the palette could not find. What this pins is the part that
 * makes them ordinary nav entries - and the part that is easy to get wrong,
 * since they all share one route name.
 */
#[AllowMockObjectsWithoutExpectations]
final class ConfigurationModuleNavViewTest extends TestCase
{
    public function testEveryVisibleTabBecomesADestination(): void
    {
        $view = $this->makeModule(['general', 'seo', 'navigation'], themes: false)->getModuleNavView();

        self::assertNotNull($view);
        self::assertSame('configuration', $view->moduleId);

        $settings = $this->group($view->groups, 'settings');
        self::assertSame(
            ['general', 'seo', 'navigation'],
            array_map(static fn (NavItem $item): string => (string) $item->routeParameters['tab'], $settings->items),
        );
    }

    // The trap: eleven entries on `suite_configuration_settings_tab`. If the
    // route name stayed the stable key, hiding one tab would hide all eleven and
    // every one of them would read as the active row.
    public function testTabsShareARouteNameButNotAStableKey(): void
    {
        $settings = $this->group($this->makeModule(['general', 'seo'], themes: false)->getModuleNavView()->groups, 'settings');

        $routes = array_map(static fn (NavItem $item): string => $item->route, $settings->items);
        self::assertSame(
            ['suite_configuration_settings_tab', 'suite_configuration_settings_tab'],
            $routes,
        );

        $keys = array_map(static fn (NavItem $item): string => $item->stableKey(), $settings->items);
        self::assertSame(
            ['configuration.settings.tab.general', 'configuration.settings.tab.seo'],
            $keys,
        );
        self::assertCount(2, array_unique($keys));
    }

    public function testTabsCarryTheirOwnLabelAndDescriptionKeys(): void
    {
        $settings = $this->group($this->makeModule(['seo'])->getModuleNavView()->groups, 'settings');

        self::assertSame('suite.settings.tabs.seo', $settings->items[0]->labelKey);
        self::assertSame('suite.settings.tabs.seo_description', $settings->items[0]->descriptionKey);
    }

    public function testTabsStayBehindTheSettingsPrivilege(): void
    {
        $settings = $this->group($this->makeModule(['general'])->getModuleNavView()->groups, 'settings');

        self::assertSame('configuration.settings.manage', $settings->items[0]->requiredPrivilege);
    }

    // A tab nobody gave an icon to gets the sliders glyph, not the document one:
    // a page of knobs should not look like a page of content.
    public function testAnUnknownTabFallsBackToTheSlidersIcon(): void
    {
        $settings = $this->group($this->makeModule(['tracking'])->getModuleNavView()->groups, 'settings');

        self::assertSame('sliders-horizontal', $settings->items[0]->icon);
    }

    /**
     * Themes is a page of its own, not a tab, but it reads as part of
     * Réglages: inside the section, right after Apparence. In a headerless
     * group of its own it looked like a tab and stayed in view when Réglages
     * was folded (10/10/2026).
     */
    public function testThemesSitsInsideTheSettingsAfterAppearance(): void
    {
        $view = $this->makeModule(['general', 'appearance', 'seo'])->getModuleNavView();

        self::assertSame(['settings'], array_map(
            static fn (ModuleNavGroup $group): string => $group->id,
            $view->groups,
        ));
        self::assertSame(
            ['suite_configuration_settings_tab', 'suite_configuration_settings_tab', 'suite_configuration_themes', 'suite_configuration_settings_tab'],
            array_map(static fn (NavItem $item): string => $item->route, $this->group($view->groups, 'settings')->items),
        );
    }

    public function testThemesClosesTheSettingsWhenThereIsNoAppearanceTab(): void
    {
        $items = $this->group($this->makeModule(['general'])->getModuleNavView()->groups, 'settings')->items;

        self::assertSame('suite_configuration_themes', $items[array_key_last($items)]->route);
    }

    public function testNoViewWhenTheModuleSuiteIsOff(): void
    {
        self::assertNull($this->makeModule(['general'], suite: false)->getModuleNavView());
    }

    public function testNoViewWhenNeitherSettingsNorThemesIsOn(): void
    {
        self::assertNull(
            $this->makeModule(['general'], settings: false, themes: false)->getModuleNavView(),
        );
    }

    public function testThemesAloneStillEarnsAView(): void
    {
        $view = $this->makeModule([], settings: false)->getModuleNavView();

        self::assertNotNull($view);
        self::assertSame(['appearance'], array_map(
            static fn (ModuleNavGroup $group): string => $group->id,
            $view->groups,
        ));
    }

    /** @param list<ModuleNavGroup> $groups */
    private function group(array $groups, string $id): ModuleNavGroup
    {
        foreach ($groups as $group) {
            if ($group->id === $id) {
                return $group;
            }
        }

        self::fail("Group {$id} not found");
    }

    /**
     * `ConfigurationContext` and `SettingsTabAccess` are both final, so they are
     * built for real over mocked collaborators rather than doubled - which also
     * means the tab-visibility rule under test here is the production one.
     *
     * @param list<string> $tabIds
     */
    private function makeModule(
        array $tabIds,
        bool $suite = true,
        bool $settings = true,
        bool $themes = true,
    ): ConfigurationModule {
        $verdicts = [
            ModuleParameterEnum::ConfigurationSuite->value => $suite,
            ModuleParameterEnum::ConfigurationSettings->value => $settings,
            ModuleParameterEnum::ConfigurationThemes->value => $themes,
        ];

        $moduleAccessChecker = $this->createMock(ModuleAccessChecker::class);
        $moduleAccessChecker->method('isEnabled')->willReturnCallback(
            static function (ModuleParameterEnum|string $toggle) use ($verdicts): bool {
                $key = $toggle instanceof ModuleParameterEnum ? $toggle->value : $toggle;

                return $verdicts[$key] ?? true;
            },
        );

        $security = $this->createMock(AuthorizationCheckerInterface::class);
        $security->method('isGranted')->willReturn(true);

        $tabs = array_map(
            static fn (string $id): ConfigurationTab => new ConfigurationTab(id: $id, priority: 10, fields: [], alwaysVisible: true),
            $tabIds,
        );

        $provider = new class($tabs) implements ConfigurationTabProviderInterface {
            /** @param list<ConfigurationTab> $tabs */
            public function __construct(private readonly array $tabs) {}

            public function getTabs(): array
            {
                return $this->tabs;
            }
        };

        return new ConfigurationModule(
            new ConfigurationContext($moduleAccessChecker),
            new SettingsTabAccess(
                new SettingDefinitionRegistry([$provider]),
                $moduleAccessChecker,
                $security,
            ),
        );
    }
}
