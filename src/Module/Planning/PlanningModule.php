<?php

declare(strict_types=1);

namespace Aurora\Module\Planning;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * The calendar, as the rest of the application sees it.
 *
 * One nav item and not a section of several: a calendar is one screen. The
 * calendars themselves are managed from inside it, the way Google does it,
 * rather than as a second list in the menu - a sidebar of four calendars does
 * not need a page.
 */
final readonly class PlanningModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function __construct(private PlanningContext $planningContext) {}

    public function getId(): string
    {
        return 'planning';
    }

    public function getPermissions(): array
    {
        return [
            new NavPermission('planning.calendars.view'),
            new NavPermission('planning.calendars.manage'),
            new NavPermission('planning.events.create'),
            new NavPermission('planning.events.edit'),
            new NavPermission('planning.events.delete'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->planningContext->isBackendEnabled()) {
            return [];
        }

        return [
            new NavSection('planning', [
                new NavItem(
                    'backend_planning_calendar',
                    'backend.nav.planning',
                    'calendar-days',
                    requiredPrivilege: 'planning.calendars.view',
                    descriptionKey: 'backend.nav.planning_description',
                ),
            ], priority: 25),
        ];
    }

    public function getCatalogNavSections(): array
    {
        return [
            new NavSection('planning', [
                new NavItem(
                    'backend_planning_calendar',
                    'backend.nav.planning',
                    'calendar-days',
                    requiredPrivilege: 'planning.calendars.view',
                    descriptionKey: 'backend.nav.planning_description',
                ),
            ], priority: 25),
        ];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::PlanningBackend->toToggle(),
        ];
    }
}
