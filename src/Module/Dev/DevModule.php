<?php

declare(strict_types=1);

namespace Aurora\Module\Dev;

use Aurora\Core\Module\Contract\ModuleInterface;
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
final readonly class DevModule implements ModuleInterface
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
}
