<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Dev;

use Aurora\Module\Dev\DevModule;
use PHPUnit\Framework\TestCase;

/**
 * The administration tabs, once they became menu entries.
 *
 * The easy migration of the six: every tab already had a route of its own,
 * kept in the address by `useUrlSyncedState`, so nothing had to be invented -
 * unlike the settings tabs, which shared one route name and needed `routeParams`
 * and a stable key before they could move.
 */
final class DevModuleNavViewTest extends TestCase
{
    public function testEveryTabBecomesAnEntry(): void
    {
        $view = (new DevModule())->getModuleNavView();

        self::assertNotNull($view);
        self::assertSame('dev', $view->moduleId);
        self::assertCount(1, $view->groups);
        self::assertCount(7, $view->groups[0]->items);
    }

    public function testEachEntryPointsAtItsOwnRoute(): void
    {
        $routes = array_map(
            static fn ($item): string => $item->route,
            (new DevModule())->getModuleNavView()->groups[0]->items,
        );

        self::assertSame([
            'dev_dashboard',
            'dev_users',
            'dev_access_requests',
            'dev_audit',
            'dev_permissions',
            'dev_modules',
            'dev_mount_points',
        ], $routes);
        self::assertSame($routes, array_unique($routes));
    }

    /**
     * Each entry answers for itself. The section's own gate is what the reader
     * passes to arrive; `NavItemResolver` filters these one at a time, and an
     * entry with no privilege of its own would be shown to anybody who got
     * this far.
     */
    public function testEveryEntryCarriesTheDevRole(): void
    {
        foreach ((new DevModule())->getModuleNavView()->groups[0]->items as $item) {
            self::assertSame('ROLE_DEV', $item->requiredPrivilege, $item->route);
        }
    }

    /** Seven destinations are a list of links; nothing here needs a panel. */
    public function testItDeclaresNoPanel(): void
    {
        self::assertNull((new DevModule())->getModuleNavView()->panelComponent);
    }
}
