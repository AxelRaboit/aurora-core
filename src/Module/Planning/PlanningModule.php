<?php

declare(strict_types=1);

namespace Aurora\Module\Planning;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleNavViewProviderInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\ModuleNavGroup;
use Aurora\Core\Module\Nav\ModuleNavView;
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
final readonly class PlanningModule implements ModuleInterface, ModuleNavViewProviderInterface, ModuleToggleProviderInterface
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

        return [new NavSection('planning', [$this->calendarNavItem()], priority: 25)];
    }

    /** One destination, shared by the project menu and the module view. */
    private function calendarNavItem(): NavItem
    {
        return new NavItem(
            'backend_planning_calendar',
            'backend.nav.planning',
            'calendar-days',
            requiredPrivilege: 'planning.calendars.view',
            descriptionKey: 'backend.nav.planning_description',
        );
    }

    public function getCatalogNavSections(): array
    {
        return [new NavSection('planning', [$this->calendarNavItem()], priority: 25)];
    }

    /**
     * The calendars, in the menu, through the panel.
     *
     * They are not destinations - a calendar is a filter over one grid, not a
     * page - so they cannot be `NavItem`s. What they need is a list with a
     * visibility toggle, a count and two create buttons, which is what a panel
     * is for.
     *
     * This one gives width back twice over. The list was a 13 rem column beside
     * the grid, which cost 224 pixels of a seven-day week - 32 pixels per day,
     * a whole event title. It was moved to a row above the grid for that, which
     * bought the width back and spent a row of height. The menu's column is
     * already on screen, so it costs the grid neither, and the row goes with
     * it - along with the second rendering of the list that the row was.
     */
    public function getModuleNavView(): ?ModuleNavView
    {
        if (!$this->planningContext->isBackendEnabled()) {
            return null;
        }

        return new ModuleNavView(
            'planning',
            [new ModuleNavGroup('destinations', [$this->calendarNavItem()])],
            panelComponent: 'planning/backend/planning/CalendarListPanel',
        );
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::PlanningBackend->toToggle(),
        ];
    }
}
