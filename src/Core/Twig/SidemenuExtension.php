<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Core\Module\Nav\NavItemCounter;
use Aurora\Core\Module\Service\ModuleNavResolver;
use Aurora\Core\Module\Service\ModuleRegistry;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Twig\Attribute\AsTwigFunction;

final readonly class SidemenuExtension
{
    public function __construct(
        private ModuleRegistry $moduleRegistry,
        private ModuleNavResolver $moduleNavResolver,
        private SettingRepository $settingRepository,
        private NavItemCounter $navItemCounter,
    ) {}

    /**
     * The menu's sections, resolved for the reader.
     *
     * `$counted`: write each entry's figure into it ({@see NavItemCounter}).
     * Asked by the layout, which draws the menu; not by the navigation
     * settings, which list the same entries to rename and reorder them and
     * have no use for the queries.
     */
    #[AsTwigFunction(name: 'sidemenu_nav_sections')]
    public function getSidemenuNavSections(bool $counted = false): array
    {
        $sections = $this->moduleRegistry->getNavSections();

        return $counted ? $this->navItemCounter->annotateContainers($sections) : $sections;
    }

    /**
     * The module view for the given route, or null when the menu stays in its
     * project view.
     *
     * Takes the route as an argument rather than reading it from a
     * `RequestStack`: the layout already has it in hand for `activeRoute`, and
     * passing it keeps this extension free of request state - which is also
     * what makes it testable without a kernel.
     *
     * @return array<string, mixed>|null
     */
    #[AsTwigFunction(name: 'module_nav_view')]
    public function getModuleNavView(?string $route): ?array
    {
        $view = $this->moduleNavResolver->resolveForRoute($route);
        if (null === $view) {
            return null;
        }

        // The same figures as the main menu: an entry drawn in a module's own
        // view is the same destination, and must not lose its count there.
        $view['groups'] = $this->navItemCounter->annotateContainers($view['groups']);

        return $view;
    }

    #[AsTwigFunction(name: 'nav_section_aliases')]
    public function getNavSectionAliases(): array
    {
        return $this->decodeJsonMap(ApplicationParameterEnum::NavSectionAliases->value);
    }

    #[AsTwigFunction(name: 'nav_item_aliases')]
    public function getNavItemAliases(): array
    {
        return $this->decodeJsonMap(ApplicationParameterEnum::NavItemAliases->value);
    }

    /**
     * @return list<string> ordered section IDs (any not in the list lands after
     *                      the explicit entries, keeping their natural priority)
     */
    #[AsTwigFunction(name: 'nav_section_order')]
    public function getNavSectionOrder(): array
    {
        $decoded = json_decode(
            $this->settingRepository->get(ApplicationParameterEnum::NavSectionOrder->value, '[]') ?? '[]',
            true,
        );

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }

    /**
     * @return array<string, list<string>> sectionId → ordered list of NavItem route names
     */
    #[AsTwigFunction(name: 'nav_item_order')]
    public function getNavItemOrder(): array
    {
        $decoded = json_decode(
            $this->settingRepository->get(ApplicationParameterEnum::NavItemOrder->value, '{}') ?? '{}',
            true,
        );

        if (!is_array($decoded)) {
            return [];
        }

        $clean = [];
        foreach ($decoded as $sectionId => $items) {
            if (!is_string($sectionId)) {
                continue;
            }

            if (!is_array($items)) {
                continue;
            }

            $clean[$sectionId] = array_values(array_filter($items, is_string(...)));
        }

        return $clean;
    }

    private function decodeJsonMap(string $key): array
    {
        $json = $this->settingRepository->get($key, '{}');
        $decoded = json_decode($json ?? '{}', true);

        return is_array($decoded) ? $decoded : [];
    }
}
