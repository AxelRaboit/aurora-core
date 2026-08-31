<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged;

use Aurora\Core\Module\Nav\ModuleNavGroup;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Ged\GedContext;
use Aurora\Module\Ged\GedModule;
use PHPUnit\Framework\TestCase;

final class GedModuleTest extends TestCase
{
    private function makeModule(
        bool $backendEnabled = true,
        bool $documentsEnabled = true,
        bool $categoriesEnabled = true,
        bool $tagsEnabled = true,
        bool $foldersEnabled = true,
    ): GedModule {
        $checker = $this->createStub(ModuleAccessChecker::class);
        $checker->method('isEnabled')->willReturnCallback(
            static fn (ModuleParameterEnum $param): bool => match ($param) {
                ModuleParameterEnum::GedBackend => $backendEnabled,
                ModuleParameterEnum::GedDocuments => $documentsEnabled,
                ModuleParameterEnum::GedCategories => $categoriesEnabled,
                ModuleParameterEnum::GedTags => $tagsEnabled,
                ModuleParameterEnum::GedFolders => $foldersEnabled,
                default => false,
            },
        );

        return new GedModule(new GedContext($checker));
    }

    public function testGetIdReturnsGed(): void
    {
        self::assertSame('ged', $this->makeModule()->getId());
    }

    public function testGetPermissionsCountsTen(): void
    {
        self::assertCount(10, $this->makeModule()->getPermissions());
    }

    public function testGetNavSectionsReturnsEmptyWhenBackendDisabled(): void
    {
        self::assertSame([], $this->makeModule(backendEnabled: false)->getNavSections());
    }

    /**
     * Folders lost their menu row when their page was deleted: the tree, its
     * writes and its ordering live in the module view's panel now, which is on
     * screen throughout the module rather than on one page. The toggle still
     * decides whether that panel is drawn.
     */
    public function testFoldersHaveNoRowOfTheirOwn(): void
    {
        $routes = array_map(
            static fn ($item): string => $item->route,
            $this->makeModule()->getCatalogNavSections()[0]->items,
        );

        self::assertNotContains('backend_ged_folders', $routes);
    }

    public function testGetNavSectionsReturnsEmptyWhenAllSubFeaturesDisabled(): void
    {
        $sections = $this->makeModule(
            documentsEnabled: false,
            categoriesEnabled: false,
            tagsEnabled: false,
            foldersEnabled: false,
        )->getNavSections();

        self::assertSame([], $sections);
    }

    public function testGetNavSectionsReturnsSectionWhenAnyEnabled(): void
    {
        $sections = $this->makeModule(
            categoriesEnabled: false,
            tagsEnabled: false,
            foldersEnabled: false,
        )->getNavSections();

        self::assertCount(1, $sections);
        self::assertContainsOnlyInstancesOf(NavSection::class, $sections);
    }

    public function testGetCatalogNavSectionsReturnsAllItems(): void
    {
        $sections = $this->makeModule(backendEnabled: false)->getCatalogNavSections();

        self::assertCount(1, $sections);
    }

    public function testGetTogglesReturnsSixEntries(): void
    {
        self::assertCount(6, $this->makeModule()->getToggles());
    }

    public function testGetModuleNavViewReturnsNullWhenBackendDisabled(): void
    {
        self::assertNull($this->makeModule(backendEnabled: false)->getModuleNavView());
    }

    /**
     * Null, not an empty view: null means "this module has no second level" and
     * the menu stays on the project view. An empty view would switch the column
     * to a module menu with nothing in it.
     */
    public function testGetModuleNavViewReturnsNullWhenAllSubFeaturesDisabled(): void
    {
        $view = $this->makeModule(
            documentsEnabled: false,
            categoriesEnabled: false,
            tagsEnabled: false,
            foldersEnabled: false,
        )->getModuleNavView();

        self::assertNull($view);
    }

    public function testGetModuleNavViewGroupsEveryEnabledDestinationUnderOneHeaderlessGroup(): void
    {
        $view = $this->makeModule()->getModuleNavView();

        self::assertNotNull($view);
        self::assertSame('ged', $view->moduleId);
        self::assertCount(1, $view->groups);
        self::assertContainsOnlyInstancesOf(ModuleNavGroup::class, $view->groups);
        self::assertNull($view->groups[0]->labelKey);
        self::assertCount(3, $view->groups[0]->items);
    }

    /**
     * The module view and the project menu answer the same question - which of
     * this module's destinations are reachable - so they must never disagree.
     * They were two literal copies of the same four entries before the view
     * made it three.
     */
    public function testGetModuleNavViewShowsTheSameDestinationsAsTheProjectMenu(): void
    {
        $module = $this->makeModule(categoriesEnabled: false);

        $sectionRoutes = array_map(
            static fn ($item): string => $item->route,
            $module->getNavSections()[0]->items,
        );
        $viewRoutes = array_map(
            static fn ($item): string => $item->route,
            $module->getModuleNavView()->groups[0]->items,
        );

        self::assertSame(['backend_ged_documents', 'backend_ged_tags'], $sectionRoutes);
        self::assertSame($sectionRoutes, $viewRoutes);
    }

    public function testGetModuleNavViewNamesTheFolderTreePanel(): void
    {
        self::assertSame(
            'ged/backend/documents/FolderTreePanel',
            $this->makeModule()->getModuleNavView()->panelComponent,
        );
    }

    /**
     * A tree of folders whose rows lead to a listing nobody may read is a
     * decoration, and a tree with folders turned off has nothing to draw.
     */
    public function testGetModuleNavViewDropsThePanelWhenTheTreeWouldBeUseless(): void
    {
        self::assertNull($this->makeModule(foldersEnabled: false)->getModuleNavView()->panelComponent);
        self::assertNull($this->makeModule(documentsEnabled: false)->getModuleNavView()->panelComponent);
    }
}
