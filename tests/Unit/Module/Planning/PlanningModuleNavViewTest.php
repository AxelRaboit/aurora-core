<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Planning\PlanningContext;
use Aurora\Module\Planning\PlanningModule;
use PHPUnit\Framework\TestCase;

/**
 * Planning's calendars are a panel, not entries: a calendar filters one grid,
 * it is not a page you can go to.
 */
final class PlanningModuleNavViewTest extends TestCase
{
    private function makeModule(bool $backend = true): PlanningModule
    {
        $checker = $this->createStub(ModuleAccessChecker::class);
        $checker->method('isEnabled')->willReturnCallback(
            static fn (ModuleParameterEnum $param): bool => ModuleParameterEnum::PlanningBackend === $param && $backend,
        );

        return new PlanningModule(new PlanningContext($checker));
    }

    public function testItDeclaresTheCalendarPanel(): void
    {
        $view = $this->makeModule()->getModuleNavView();

        self::assertNotNull($view);
        self::assertSame('planning', $view->moduleId);
        self::assertSame(
            'planning/backend/planning/CalendarListPanel',
            $view->panelComponent,
        );
    }

    /**
     * One destination, and the same one the project menu shows - they come from
     * the same builder, so they cannot drift apart.
     */
    public function testItShowsTheSameDestinationAsTheProjectMenu(): void
    {
        $module = $this->makeModule();

        self::assertSame(
            $module->getNavSections()[0]->items[0]->route,
            $module->getModuleNavView()->groups[0]->items[0]->route,
        );
    }

    public function testItDeclaresNothingWhenTheModuleIsOff(): void
    {
        self::assertNull($this->makeModule(backend: false)->getModuleNavView());
    }
}
