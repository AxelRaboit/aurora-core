<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Service;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleNavViewProviderInterface;
use Aurora\Core\Module\Nav\ModuleNavView;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavItemResolver;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Answers the one question the two-view side menu needs and nothing else could
 * answer: **which module does the current route belong to, and what does its
 * menu look like?**.
 *
 * Until now nothing resolved that server-side. The active section was inferred
 * in the browser by `activeRoute.startsWith(item.route)`, which is fine for
 * tinting a row that is already on screen but not for deciding *what to render*
 * - a client-side answer would paint the project view first and swap it a frame
 * later, in front of the reader.
 *
 * The match is the same prefix rule the menu has always used, with one addition:
 * when several prefixes match, the **longest** one wins. That is what keeps
 * `dev_dashboard` from being claimed by a module whose prefix is merely `dev_`,
 * and it is the only tie-break that does not depend on module registration
 * order - which is DI-dependent, so unstable.
 */
final readonly class ModuleNavResolver
{
    /** @param iterable<ModuleInterface> $modules */
    public function __construct(
        private iterable $modules,
        private NavItemResolver $navItemResolver,
        private Security $userSecurity,
    ) {}

    /**
     * The module view for the given route, or null when the menu should stay in
     * its project view.
     *
     * Null covers four distinct cases, deliberately collapsed into one because
     * the caller does the same thing in all four: no route (a command, a
     * sub-request), no module owns the route, the owning module declares no view,
     * or everything the view declared was filtered out for this user.
     *
     * @return array{moduleId: string, groups: list<array{id: string, labelKey: ?string, items: list<array<string, mixed>>}>, panelComponent: ?string}|null
     */
    public function resolveForRoute(?string $route): ?array
    {
        if (null === $route || '' === $route) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach ($this->modules as $module) {
            if (!$module instanceof ModuleNavViewProviderInterface) {
                continue;
            }

            $view = $module->getModuleNavView();
            if (!$view instanceof ModuleNavView) {
                continue;
            }

            // A module absent from the main menu - switched off, or invisible to
            // this user - must not take the column over. `getNavSections()`
            // already returns nothing in that case, so it is the cheapest and
            // most honest gate available: the module view follows the menu.
            if ([] === $module->getNavSections()) {
                continue;
            }

            $length = $this->longestMatchingPrefix($route, $module, $view);
            if ($length > $bestLength) {
                $best = $view;
                $bestLength = $length;
            }
        }

        if (!$best instanceof ModuleNavView) {
            return null;
        }

        return $this->resolveView($best);
    }

    /**
     * Length of the longest route prefix this module claims that `$route`
     * starts with, or -1 when it claims none.
     *
     * Prefixes come from both the module's main-menu items and its view's own
     * items: a module can own destinations that never appear in the project
     * view - which is the whole point of the second level - and those routes
     * still belong to it.
     */
    private function longestMatchingPrefix(string $route, ModuleInterface $module, ModuleNavView $view): int
    {
        $longest = -1;

        foreach ($this->collectPrefixes($module, $view) as $prefix) {
            if ('' === $prefix) {
                continue;
            }

            if (!str_starts_with($route, $prefix)) {
                continue;
            }

            $length = mb_strlen($prefix);
            if ($length > $longest) {
                $longest = $length;
            }
        }

        return $longest;
    }

    /**
     * Every route prefix the module claims. `activeRoutePrefix` wins over the
     * route name when set, for the same reason the menu highlights on it: it is
     * the module's own statement of "these routes are mine".
     *
     * @return list<string>
     */
    private function collectPrefixes(ModuleInterface $module, ModuleNavView $view): array
    {
        $prefixes = [];

        foreach ($module->getNavSections() as $section) {
            $this->collectItemPrefixes($section->items, $prefixes);
        }

        foreach ($view->groups as $group) {
            $this->collectItemPrefixes($group->items, $prefixes);
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @param NavItem[]    $items
     * @param list<string> $prefixes accumulator, by reference
     */
    private function collectItemPrefixes(array $items, array &$prefixes): void
    {
        foreach ($items as $item) {
            $prefixes[] = $item->activeRoutePrefix ?? $item->route;

            if ([] !== $item->children) {
                $this->collectItemPrefixes($item->children, $prefixes);
            }
        }
    }

    /**
     * @return array{moduleId: string, groups: list<array{id: string, labelKey: ?string, items: list<array<string, mixed>>}>, panelComponent: ?string}|null
     */
    private function resolveView(ModuleNavView $view): ?array
    {
        $user = $this->userSecurity->getUser();
        // The same per-user hide list as the main menu, matched on route names -
        // so a user who hid a destination keeps it hidden wherever it is drawn.
        // Nothing to add for it to cover the module view: it was always by route.
        $hiddenItems = $user instanceof CoreUserInterface ? $user->getHiddenNavItems() : [];

        $groups = [];
        foreach ($view->groups as $group) {
            $items = $this->navItemResolver->resolveAll($group->items, $hiddenItems);

            if ([] === $items) {
                continue;
            }

            $groups[] = [
                'id' => $group->id,
                'labelKey' => $group->labelKey,
                'items' => $items,
            ];
        }

        // An empty column is worse than no column: it says "you are somewhere"
        // while offering nowhere to go. Fall back to the project view instead.
        if ([] === $groups && null === $view->panelComponent) {
            return null;
        }

        return [
            'moduleId' => $view->moduleId,
            'groups' => $groups,
            'panelComponent' => $view->panelComponent,
        ];
    }
}
