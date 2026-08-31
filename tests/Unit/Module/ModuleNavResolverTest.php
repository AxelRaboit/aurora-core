<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleNavViewProviderInterface;
use Aurora\Core\Module\Nav\ModuleNavGroup;
use Aurora\Core\Module\Nav\ModuleNavView;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavItemResolver;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Core\Module\Service\ModuleNavResolver;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[AllowMockObjectsWithoutExpectations]
final class ModuleNavResolverTest extends TestCase
{
    public function testRouteOutsideAnyModuleKeepsTheProjectView(): void
    {
        $resolver = $this->makeResolver([new GedStubModule()]);

        self::assertNull($resolver->resolveForRoute('backend_dashboard'));
    }

    public function testNullRouteKeepsTheProjectView(): void
    {
        $resolver = $this->makeResolver([new GedStubModule()]);

        self::assertNull($resolver->resolveForRoute(null));
    }

    public function testModuleClaimingThePrefixIsResolved(): void
    {
        $resolver = $this->makeResolver([new GedStubModule()]);

        $view = $resolver->resolveForRoute('backend_ged_documents');

        self::assertNotNull($view);
        self::assertSame('ged', $view['moduleId']);
        self::assertSame(['library'], array_column($view['groups'], 'id'));
        self::assertSame('ged/backend/documents/FolderTreePanel', $view['panelComponent']);
    }

    public function testARouteOnlyTheModuleViewDeclaresStillResolves(): void
    {
        // `backend_ged_trash` is in no NavSection - it exists only at module
        // level, which is the whole point of the second view.
        $resolver = $this->makeResolver([new GedStubModule()]);

        $view = $resolver->resolveForRoute('backend_ged_trash');

        self::assertNotNull($view);
        self::assertSame('ged', $view['moduleId']);
    }

    public function testModuleWithoutTheProviderInterfaceIsSkipped(): void
    {
        $resolver = $this->makeResolver([new PlainStubModule()]);

        self::assertNull($resolver->resolveForRoute('backend_plain_index'));
    }

    public function testModuleAbsentFromTheMainMenuDoesNotTakeTheColumnOver(): void
    {
        // A disabled module returns no NavSection; its view must not show either,
        // or a switched-off module would still own the side menu.
        $resolver = $this->makeResolver([new DisabledStubModule()]);

        self::assertNull($resolver->resolveForRoute('backend_off_index'));
    }

    public function testLongestPrefixWinsOverRegistrationOrder(): void
    {
        // `dev_` is declared first and matches; `dev_tools` is longer and must
        // win regardless of the order the DI container yielded the modules in.
        $resolver = $this->makeResolver([new DevStubModule(), new DevToolsStubModule()]);
        self::assertSame('dev_tools', $resolver->resolveForRoute('dev_tools_profiler')['moduleId']);

        $reversed = $this->makeResolver([new DevToolsStubModule(), new DevStubModule()]);
        self::assertSame('dev_tools', $reversed->resolveForRoute('dev_tools_profiler')['moduleId']);
    }

    public function testShorterPrefixStillWinsItsOwnRoutes(): void
    {
        $resolver = $this->makeResolver([new DevStubModule(), new DevToolsStubModule()]);

        self::assertSame('dev', $resolver->resolveForRoute('dev_dashboard')['moduleId']);
    }

    public function testPrivilegeFiltersItemsOut(): void
    {
        $resolver = $this->makeResolver([new GedStubModule()], granted: false);

        $view = $resolver->resolveForRoute('backend_ged_documents');

        // Every link needed a privilege the user lacks, but the panel remains -
        // so the view still stands rather than dropping to the project menu.
        self::assertNotNull($view);
        self::assertSame([], $view['groups']);
        self::assertSame('ged/backend/documents/FolderTreePanel', $view['panelComponent']);
    }

    public function testALinksOnlyViewEmptiedByPrivilegeFallsBackToTheProjectView(): void
    {
        $resolver = $this->makeResolver([new LinksOnlyStubModule()], granted: false);

        self::assertNull($resolver->resolveForRoute('backend_links_index'));
    }

    public function testUserHiddenItemsAreExcluded(): void
    {
        $user = $this->createMock(CoreUserInterface::class);
        $user->method('getHiddenNavItems')->willReturn(['backend_ged_trash']);

        $resolver = $this->makeResolver([new GedStubModule()], user: $user);

        $view = $resolver->resolveForRoute('backend_ged_documents');

        $keys = array_column($view['groups'][0]['items'], 'key');
        self::assertNotContains('backend_ged_trash', $keys);
        self::assertContains('backend_ged_documents', $keys);
    }

    public function testGroupLabelKeyIsCarriedThroughUntranslated(): void
    {
        // Translation is the Vue side's job - the resolver hands over keys so
        // the payload stays locale-independent and cacheable.
        $resolver = $this->makeResolver([new GedStubModule()]);

        $view = $resolver->resolveForRoute('backend_ged_documents');

        self::assertSame('backend.nav.ged_groups.library', $view['groups'][0]['labelKey']);
    }

    // The resolver is asked on every backend page render, and a view is not
    // always cheap to declare - Configuration's has to read the contributed
    // settings tabs. A module whose routes are nowhere near the current one
    // must not pay that cost, nor make the page pay it.
    public function testAModuleIsNotAskedForItsViewWhenTheRouteIsNotItsOwn(): void
    {
        $counted = new CountingStubModule();
        $resolver = $this->makeResolver([new GedStubModule(), $counted]);

        $resolver->resolveForRoute('backend_ged_documents');

        self::assertSame(0, $counted->calls);
    }

    public function testTheMatchingModuleIsAskedExactlyOnce(): void
    {
        $counted = new CountingStubModule();
        $resolver = $this->makeResolver([$counted]);

        $resolver->resolveForRoute('backend_counted_index');

        self::assertSame(1, $counted->calls);
    }

    // Both passes can reach the same module when the first one finds nothing to
    // resolve; the memo is what keeps that from costing twice.
    public function testAViewIsBuiltOnceEvenWhenBothPassesRun(): void
    {
        // Its links are all denied, so the first pass resolves nothing and the
        // second pass runs - reaching the same module again.
        $counted = new CountingStubModule();
        $resolver = $this->makeResolver([$counted], granted: false);

        $resolver->resolveForRoute('backend_counted_index');

        self::assertSame(1, $counted->calls);
    }

    /** @param list<ModuleInterface> $modules */
    private function makeResolver(
        array $modules,
        bool $granted = true,
        ?CoreUserInterface $user = null,
    ): ModuleNavResolver {
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn($granted);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route): string => '/'.$route);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return new ModuleNavResolver(
            modules: $modules,
            navItemResolver: new NavItemResolver($authChecker, $urlGenerator),
            userSecurity: $security,
        );
    }
}

/** Links + a panel, and one route that exists only at module level. */
final class GedStubModule implements ModuleInterface, ModuleNavViewProviderInterface
{
    public function getId(): string
    {
        return 'ged';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        return [new NavSection('ged', [
            new NavItem('backend_ged_documents', 'nav.documents', 'folder-open', requiredPrivilege: 'ged.documents.view'),
        ], priority: 35)];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();
    }

    public function getModuleNavView(): ?ModuleNavView
    {
        return new ModuleNavView(
            moduleId: 'ged',
            groups: [
                new ModuleNavGroup('library', [
                    new NavItem('backend_ged_documents', 'nav.documents', 'folder-open', requiredPrivilege: 'ged.documents.view'),
                    new NavItem('backend_ged_trash', 'nav.trash', 'trash', requiredPrivilege: 'ged.documents.view'),
                ], labelKey: 'backend.nav.ged_groups.library'),
            ],
            panelComponent: 'ged/backend/documents/FolderTreePanel',
        );
    }
}

/** No provider interface - the resolver must ignore it entirely. */
final class PlainStubModule implements ModuleInterface
{
    public function getId(): string
    {
        return 'plain';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        return [new NavSection('plain', [new NavItem('backend_plain_index', 'nav.plain', 'file-text')])];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();
    }
}

/** Declares a view but contributes no NavSection - i.e. switched off. */
final class DisabledStubModule implements ModuleInterface, ModuleNavViewProviderInterface
{
    public function getId(): string
    {
        return 'off';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        return [];
    }

    public function getCatalogNavSections(): array
    {
        return [new NavSection('off', [new NavItem('backend_off_index', 'nav.off', 'file-text')])];
    }

    public function getModuleNavView(): ?ModuleNavView
    {
        return new ModuleNavView('off', [
            new ModuleNavGroup('main', [new NavItem('backend_off_index', 'nav.off', 'file-text')]),
        ]);
    }
}

/** Claims the bare `dev_` prefix, the way DevModule does. */
final class DevStubModule implements ModuleInterface, ModuleNavViewProviderInterface
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
        return [new NavSection('dev', [
            new NavItem('dev_dashboard', 'nav.administration', 'shield', activeRoutePrefix: 'dev_'),
        ], priority: 1000)];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();
    }

    public function getModuleNavView(): ?ModuleNavView
    {
        return new ModuleNavView('dev', [
            new ModuleNavGroup('main', [new NavItem('dev_dashboard', 'nav.administration', 'shield')]),
        ]);
    }
}

/** Claims a longer prefix that overlaps DevStubModule's. */
final class DevToolsStubModule implements ModuleInterface, ModuleNavViewProviderInterface
{
    public function getId(): string
    {
        return 'dev_tools';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        return [new NavSection('dev_tools', [
            new NavItem('dev_tools_profiler', 'nav.profiler', 'gauge'),
        ], priority: 1001)];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();
    }

    public function getModuleNavView(): ?ModuleNavView
    {
        return new ModuleNavView('dev_tools', [
            new ModuleNavGroup('main', [new NavItem('dev_tools_profiler', 'nav.profiler', 'gauge')]),
        ]);
    }
}

/** Links only, every one behind a privilege - nothing left when it is denied. */
final class LinksOnlyStubModule implements ModuleInterface, ModuleNavViewProviderInterface
{
    public function getId(): string
    {
        return 'links';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        return [new NavSection('links', [new NavItem('backend_links_index', 'nav.links', 'file-text')])];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();
    }

    public function getModuleNavView(): ?ModuleNavView
    {
        return new ModuleNavView('links', [
            new ModuleNavGroup('main', [
                new NavItem('backend_links_index', 'nav.links', 'file-text', requiredPrivilege: 'links.view'),
            ]),
        ]);
    }
}

/** Counts how many times its view was asked for. */
final class CountingStubModule implements ModuleInterface, ModuleNavViewProviderInterface
{
    public int $calls = 0;

    public function getId(): string
    {
        return 'counted';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        return [new NavSection('counted', [new NavItem('backend_counted_index', 'nav.counted', 'file-text')])];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();
    }

    public function getModuleNavView(): ?ModuleNavView
    {
        ++$this->calls;

        return new ModuleNavView('counted', [
            new ModuleNavGroup('main', [
                new NavItem('backend_counted_index', 'nav.counted', 'file-text', requiredPrivilege: 'counted.view'),
            ]),
        ]);
    }
}
