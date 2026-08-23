<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Core\Module\Service\ModuleRegistry;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[AllowMockObjectsWithoutExpectations]
final class ModuleRegistryFilteringTest extends TestCase
{
    public function testWithoutUserAllSectionsAreReturned(): void
    {
        $registry = $this->makeRegistry(null);

        $sections = $registry->getNavSections();

        self::assertCount(2, $sections);
        self::assertSame(['crm', 'billing'], array_column($sections, 'id'));
    }

    public function testHiddenSectionIsExcluded(): void
    {
        $user = $this->makeUser(hiddenSections: ['billing']);
        $registry = $this->makeRegistry($user);

        $sections = $registry->getNavSections();

        self::assertSame(['crm'], array_column($sections, 'id'));
    }

    public function testHiddenItemIsExcluded(): void
    {
        $user = $this->makeUser(hiddenItems: ['backend_crm_contacts']);
        $registry = $this->makeRegistry($user);

        $sections = $registry->getNavSections();
        $crmItems = array_column($sections[0]['items'], 'key');

        self::assertNotContains('backend_crm_contacts', $crmItems);
        self::assertContains('backend_crm_companies', $crmItems);
    }

    public function testSectionWhereAllItemsAreHiddenDisappears(): void
    {
        $user = $this->makeUser(hiddenItems: ['backend_crm_contacts', 'backend_crm_companies']);
        $registry = $this->makeRegistry($user);

        $sections = $registry->getNavSections();

        self::assertSame(['billing'], array_column($sections, 'id'));
    }

    public function testResolvedItemsExposeStableKeyDistinctFromRoute(): void
    {
        $registry = $this->makeRegistry(null);

        $sections = $registry->getNavSections();
        $contact = $sections[0]['items'][0];

        self::assertSame('backend_crm_contacts', $contact['key']);
        // route is exposed for active-route matching (uses prefix when set)
        self::assertSame('backend_crm_contacts', $contact['route']);
    }

    public function testGetNavPreferencesReturnsEverythingWithHiddenFlags(): void
    {
        $user = $this->makeUser(hiddenSections: ['billing'], hiddenItems: ['backend_crm_contacts']);
        $registry = $this->makeRegistry($user);

        $sections = $registry->getNavPreferences();

        self::assertCount(2, $sections, 'sections list must include hidden ones for the prefs UI');
        self::assertTrue($this->findSection($sections, 'billing')['hidden']);
        self::assertFalse($this->findSection($sections, 'crm')['hidden']);

        $crmItems = $this->findSection($sections, 'crm')['items'];
        self::assertTrue($this->findItem($crmItems, 'backend_crm_contacts')['hidden']);
        self::assertFalse($this->findItem($crmItems, 'backend_crm_companies')['hidden']);
    }

    private function makeRegistry(?CoreUserInterface $user): ModuleRegistry
    {
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route): string => '/'.$route);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        // Empty order overrides - these tests exercise the priority sort,
        // hide-filter, and privilege-filter paths, not the user-ordered
        // override path (covered by ModuleRegistryOrderOverrideTest).
        $settingRepository = $this->createMock(SettingRepository::class);
        $settingRepository->method('getOrDefault')->willReturn('[]');

        return new ModuleRegistry(
            modules: [new StubNavModule()],
            security: $authChecker,
            urlGenerator: $urlGenerator,
            userSecurity: $security,
            settingRepository: $settingRepository,
        );
    }

    /**
     * @param list<string> $hiddenSections
     * @param list<string> $hiddenItems
     */
    private function makeUser(array $hiddenSections = [], array $hiddenItems = []): CoreUserInterface
    {
        $user = $this->createMock(CoreUserInterface::class);
        $user->method('getHiddenNavSections')->willReturn($hiddenSections);
        $user->method('getHiddenNavItems')->willReturn($hiddenItems);

        return $user;
    }

    /**
     * @param array<int, array{id: string, items?: array, hidden?: bool}> $sections
     *
     * @return array<string, mixed>
     */
    private function findSection(array $sections, string $id): array
    {
        foreach ($sections as $section) {
            if ($section['id'] === $id) {
                return $section;
            }
        }
        self::fail("Section {$id} not found");
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function findItem(array $items, string $key): array
    {
        foreach ($items as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }
        self::fail("Item {$key} not found");
    }
}

/**
 * Minimal Module stub that declares 2 sections (crm + billing) with stable
 * NavItem routes. Used to exercise the filtering logic without booting the
 * full kernel or depending on which production modules are enabled.
 */
final class StubNavModule implements ModuleInterface
{
    public function getId(): string
    {
        return 'stub';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        return [
            new NavSection('crm', [
                new NavItem('backend_crm_contacts', 'nav.contacts', 'users'),
                new NavItem('backend_crm_companies', 'nav.companies', 'building'),
            ], priority: 40),
            new NavSection('billing', [
                new NavItem('backend_billing_invoices', 'nav.invoices', 'receipt'),
            ], priority: 50),
        ];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();
    }
}
