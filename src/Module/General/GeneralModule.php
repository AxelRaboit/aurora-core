<?php

declare(strict_types=1);

namespace Aurora\Module\General;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * General section - the post-login landing page (Dashboard). Sibling of
 * {@see PlatformModule}, {@see ConfigurationModule}, {@see DevModule};
 * split out of the former monolithic CoreModule in Jalon 4 so each Core
 * concern follows the "1 module class = 1 NavSection = 1 toggle root =
 * 1 context" pattern shared by business modules.
 */
final readonly class GeneralModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function __construct(private GeneralContext $generalContext) {}

    public function getId(): string
    {
        return 'general';
    }

    public function getPermissions(): array
    {
        return [
            new NavPermission('general.dashboard.view'),
            // Global search (header input - omnipresent across the backend,
            // not a NavItem in any section). Lives here because it's
            // general-purpose backend infra, not Platform-specific.
            new NavPermission('general.search.view'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->generalContext->isBackendEnabled()) {
            return [];
        }

        $items = [];

        if ($this->generalContext->isDashboardEnabled()) {
            $items[] = new NavItem('backend_dashboard', 'backend.nav.dashboard', 'layout-dashboard', requiredPrivilege: 'general.dashboard.view', descriptionKey: 'backend.nav.dashboard_description');
        }

        if ([] === $items) {
            return [];
        }

        return [new NavSection('general', $items, priority: 10)];
    }

    public function getCatalogNavSections(): array
    {
        return [
            new NavSection('general', [
                new NavItem('backend_dashboard', 'backend.nav.dashboard', 'layout-dashboard', requiredPrivilege: 'general.dashboard.view', descriptionKey: 'backend.nav.dashboard_description'),
            ], priority: 10),
        ];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::GeneralBackend->toToggle(),
            ModuleParameterEnum::GeneralDashboard->toToggle(),
        ];
    }
}
