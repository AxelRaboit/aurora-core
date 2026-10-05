<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Dev\DevModule;

/**
 * The beacon's back-office surface: a single screen listing the deployed
 * instances recorded by {@see Controller\BeaconController}, so a copy of Aurora
 * running on a domain that is not yours shows up somewhere you look (see
 * LICENSE).
 *
 * Gated by `ROLE_DEV` at the NavItem, like {@see DevModule}:
 * super-admin tooling, no end-user surface and no toggle a client could use to
 * switch the detection off.
 */
final readonly class BeaconModule implements ModuleInterface
{
    public function getId(): string
    {
        return 'beacon';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        return [
            new NavSection('beacon', [
                new NavItem('beacon_instances', 'suite.nav.beacon', 'radar', 'ROLE_DEV', 'rose', 'beacon_', descriptionKey: 'suite.nav.beacon_description'),
            ], priority: 1001),
        ];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();
    }
}
