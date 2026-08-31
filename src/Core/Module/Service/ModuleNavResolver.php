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
 *
 * **Why two passes.** `getModuleNavView()` is not always cheap: Configuration's
 * has to read the contributed settings tabs, which resolve timezones, locales
 * and the front registry. Asking every module on every backend page would put
 * that on the dashboard, on a note, on a document. So the first pass matches on
 * `getNavSections()` alone - the modules already build those for the menu - and
 * only the winner is asked for its view. The second pass exists for routes no
 * section declares, and runs only when the first found nobody.
 */
final class ModuleNavResolver
{
    /** @var array<string, ModuleNavView|null> */
    private array $viewCache = [];

    /** @param iterable<ModuleInterface> $modules */
    public function __construct(
        private readonly iterable $modules,
        private readonly NavItemResolver $navItemResolver,
        private readonly Security $userSecurity,
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

        $resolved = $this->resolveFromSections($route);
        if (null !== $resolved) {
            return $resolved;
        }

        return $this->resolveFromViews($route);
    }

    /**
     * First pass: match on what the modules already publish to the main menu.
     * Cheap, and it covers every module whose second level lives under the
     * routes its menu entry points at - which is the ordinary case.
     *
     * @return array{moduleId: string, groups: list<array<string, mixed>>, panelComponent: ?string}|null
     */
    private function resolveFromSections(string $route): ?array
    {
        /** @var list<array{module: ModuleInterface&ModuleNavViewProviderInterface, length: int}> $candidates */
        $candidates = [];

        foreach ($this->modules as $module) {
            if (!$module instanceof ModuleNavViewProviderInterface) {
                continue;
            }

            // A module absent from the main menu - switched off, or invisible to
            // this user - must not take the column over. `getNavSections()`
            // already returns nothing in that case, so it is the cheapest and
            // most honest gate available: the module view follows the menu.
            $sections = $module->getNavSections();
            if ([] === $sections) {
                continue;
            }

            $prefixes = [];
            foreach ($sections as $section) {
                $this->collectItemPrefixes($section->items, $prefixes);
            }

            $length = $this->longestMatch($route, $prefixes);
            if ($length >= 0) {
                $candidates[] = ['module' => $module, 'length' => $length];
            }
        }

        // Longest first, so the module that claims the most specific prefix is
        // asked for its view before any module that merely claims a stem of it.
        usort($candidates, static fn (array $a, array $b): int => $b['length'] <=> $a['length']);

        foreach ($candidates as $candidate) {
            $view = $this->viewOf($candidate['module']);
            if (!$view instanceof ModuleNavView) {
                continue;
            }

            $resolved = $this->resolveView($view);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Second pass: a route no section declares can still belong to a module -
     * that is exactly what a second level is for. Only reached when the first
     * pass found nobody, so the cost of asking every module lands on routes
     * that would otherwise get no column at all.
     *
     * @return array{moduleId: string, groups: list<array<string, mixed>>, panelComponent: ?string}|null
     */
    private function resolveFromViews(string $route): ?array
    {
        $best = null;
        $bestLength = -1;

        foreach ($this->modules as $module) {
            if (!$module instanceof ModuleNavViewProviderInterface) {
                continue;
            }

            if ([] === $module->getNavSections()) {
                continue;
            }

            $view = $this->viewOf($module);
            if (!$view instanceof ModuleNavView) {
                continue;
            }

            $prefixes = [];
            foreach ($view->groups as $group) {
                $this->collectItemPrefixes($group->items, $prefixes);
            }

            $length = $this->longestMatch($route, $prefixes);
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
     * Memoised per request: both passes can reach the same module, and a view
     * is a pure declaration - asking twice would only pay twice.
     */
    private function viewOf(ModuleNavViewProviderInterface&ModuleInterface $module): ?ModuleNavView
    {
        return $this->viewCache[$module->getId()] ??= $module->getModuleNavView();
    }

    /**
     * Length of the longest prefix in the list that `$route` starts with, or -1
     * when none of them does.
     *
     * @param list<string> $prefixes
     */
    private function longestMatch(string $route, array $prefixes): int
    {
        $longest = -1;

        foreach ($prefixes as $prefix) {
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
     * `activeRoutePrefix` wins over the route name when set, for the same reason
     * the menu highlights on it: it is the module's own statement of "these
     * routes are mine".
     *
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
        // The same per-user hide list as the main menu, matched on stable keys -
        // so a user who hid a destination keeps it hidden wherever it is drawn.
        // Nothing to add for it to cover the module view.
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
