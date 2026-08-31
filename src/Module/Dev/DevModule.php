<?php

declare(strict_types=1);

namespace Aurora\Module\Dev;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleNavViewProviderInterface;
use Aurora\Core\Module\Nav\ModuleNavGroup;
use Aurora\Core\Module\Nav\ModuleNavView;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavSection;

/**
 * Dev/Administration section - surfaces the developer tools dashboard
 * (audit log, access requests, advanced configuration). Gated by
 * `ROLE_DEV` at the NavItem level rather than by a toggle: this section
 * has no end-user-facing surface, only super-admin tooling.
 *
 * Does NOT implement {@see ModuleToggleProviderInterface} - there is no
 * `DevBackend` setting to turn off because the role gate is enough and
 * no client should be able to disable Aurora's dev panel.
 */
final readonly class DevModule implements ModuleInterface, ModuleNavViewProviderInterface
{
    public function getId(): string
    {
        return 'dev';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        return [
            new NavSection('dev', [
                new NavItem('dev_dashboard', 'backend.nav.administration', 'shield', 'ROLE_DEV', 'rose', 'dev_', descriptionKey: 'backend.nav.administration_description'),
            ], priority: 1000),
        ];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();
    }

    /**
     * The seven administration tabs, as menu entries.
     *
     * They were a row of tabs declared in a JavaScript array inside
     * `AdministrationApp`, which is the same arrangement the settings page had
     * before 0.9.29 - with one difference that makes this the easy case: each
     * tab **already has a route of its own**, kept in the address by
     * `useUrlSyncedState`. So there is nothing to invent here, no shared route
     * name and no stable key: seven ordinary `NavItem`s pointing at seven
     * routes that already exist.
     *
     * All seven repeat `ROLE_DEV` rather than leaning on the section's own
     * gate. The section is what the reader clicks to get here; these are what
     * the menu draws once they arrive, and each has to answer for itself -
     * `NavItemResolver` filters them one by one.
     *
     * No panel: seven destinations are a list of links, which is precisely what
     * a group of `NavItem`s is for.
     *
     * Never null, unlike the other implementations. There is no toggle to
     * consult - the dev panel is not a module a client can switch off - and
     * whether a reader may see any of this is `ROLE_DEV` on each entry, which
     * `NavItemResolver` applies. A view whose every entry is filtered out
     * already resolves to nothing upstream.
     */
    public function getModuleNavView(): ModuleNavView
    {
        return new ModuleNavView('dev', [
            new ModuleNavGroup('administration', [
                new NavItem('dev_dashboard', 'backend.tabs.overview', 'layout-dashboard', 'ROLE_DEV', descriptionKey: 'backend.tabs.overview_description'),
                new NavItem('dev_users', 'backend.tabs.users', 'users', 'ROLE_DEV', descriptionKey: 'backend.tabs.users_description'),
                new NavItem('dev_access_requests', 'backend.tabs.access_requests', 'key-round', 'ROLE_DEV', descriptionKey: 'backend.tabs.access_requests_description'),
                new NavItem('dev_audit', 'backend.tabs.audit', 'scroll-text', 'ROLE_DEV', descriptionKey: 'backend.tabs.audit_description'),
                new NavItem('dev_permissions', 'backend.tabs.permissions', 'shield-check', 'ROLE_DEV', descriptionKey: 'backend.tabs.permissions_description'),
                new NavItem('dev_modules', 'backend.tabs.modules', 'puzzle', 'ROLE_DEV', descriptionKey: 'backend.tabs.modules_description'),
                new NavItem('dev_mount_points', 'backend.tabs.mount_points', 'network', 'ROLE_DEV', descriptionKey: 'backend.tabs.mount_points_description'),
            ]),
        ]);
    }
}
