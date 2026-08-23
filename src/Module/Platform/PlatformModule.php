<?php

declare(strict_types=1);

namespace Aurora\Module\Platform;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Platform section - the organization layer of the backend (Users). Media
 * moved to {@see MediaModule} in Jalon 4.5 (cross-cutting infra),
 * Configuration (Settings, Themes) lives in {@see ConfigurationModule}
 * (admin params), and global search moved to {@see GeneralModule} in
 * Jalon 5.1 (it's a header feature, not a Platform-specific concern) -
 * this class now strictly owns user management.
 */
final readonly class PlatformModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function __construct(private PlatformContext $platformContext) {}

    public function getId(): string
    {
        return 'platform';
    }

    public function getPermissions(): array
    {
        return [
            new NavPermission('platform.users.manage'),
            new NavPermission('platform.users.module_access.manage'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->platformContext->isBackendEnabled()) {
            return [];
        }

        $items = [];

        if ($this->platformContext->isUsersEnabled()) {
            $items[] = new NavItem('backend_platform_users', 'backend.nav.users', 'users', requiredPrivilege: 'platform.users.manage', descriptionKey: 'backend.nav.users_description');
        }

        if ([] === $items) {
            return [];
        }

        return [new NavSection('platform', $items, priority: 20)];
    }

    public function getCatalogNavSections(): array
    {
        return [
            new NavSection('platform', [
                new NavItem('backend_platform_users', 'backend.nav.users', 'users', requiredPrivilege: 'platform.users.manage', descriptionKey: 'backend.nav.users_description'),
            ], priority: 20),
        ];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::PlatformBackend->toToggle(),
            ModuleParameterEnum::PlatformUsers->toToggle(),
        ];
    }
}
