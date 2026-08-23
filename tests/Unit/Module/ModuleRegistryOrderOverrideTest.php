<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Core\Module\Service\ModuleRegistry;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function is_object;

#[AllowMockObjectsWithoutExpectations]
final class ModuleRegistryOrderOverrideTest extends TestCase
{
    public function testEmptyOverrideKeepsPrioritySort(): void
    {
        $registry = $this->makeRegistry(sectionOrder: '[]', itemOrder: '{}');

        self::assertSame(['crm', 'billing', 'notes'], $this->sectionIds($registry));
    }

    public function testSectionOverrideReordersAllListed(): void
    {
        // Reverse the natural order entirely.
        $registry = $this->makeRegistry(
            sectionOrder: '["notes", "billing", "crm"]',
            itemOrder: '{}',
        );

        self::assertSame(['notes', 'billing', 'crm'], $this->sectionIds($registry));
    }

    public function testSectionOverridePartialAppendsRest(): void
    {
        // Only notes is reordered to the top - crm + billing keep their natural
        // priority order behind it.
        $registry = $this->makeRegistry(
            sectionOrder: '["notes"]',
            itemOrder: '{}',
        );

        self::assertSame(['notes', 'crm', 'billing'], $this->sectionIds($registry));
    }

    public function testSectionOverrideUnknownIdsAreIgnored(): void
    {
        // 'archived_module' doesn't exist (e.g. module was uninstalled) - should
        // be skipped without breaking the override.
        $registry = $this->makeRegistry(
            sectionOrder: '["archived_module", "notes", "crm"]',
            itemOrder: '{}',
        );

        self::assertSame(['notes', 'crm', 'billing'], $this->sectionIds($registry));
    }

    public function testItemOrderReordersWithinSection(): void
    {
        $registry = $this->makeRegistry(
            sectionOrder: '[]',
            itemOrder: '{"crm": ["backend_crm_deals", "backend_crm_companies", "backend_crm_contacts"]}',
        );

        $sections = $registry->getNavSections();
        $crmSection = array_values(array_filter($sections, static fn (array $s): bool => 'crm' === $s['id']))[0];

        self::assertSame(
            ['backend_crm_deals', 'backend_crm_companies', 'backend_crm_contacts'],
            array_column($crmSection['items'], 'key'),
        );
    }

    public function testItemOrderUnknownRoutesAreIgnored(): void
    {
        $registry = $this->makeRegistry(
            sectionOrder: '[]',
            itemOrder: '{"crm": ["backend_crm_archived", "backend_crm_deals"]}',
        );

        $sections = $registry->getNavSections();
        $crmSection = array_values(array_filter($sections, static fn (array $s): bool => 'crm' === $s['id']))[0];

        // backend_crm_deals lands first (the only known route in the override),
        // then the rest in their natural order.
        self::assertSame(
            ['backend_crm_deals', 'backend_crm_contacts', 'backend_crm_companies'],
            array_column($crmSection['items'], 'key'),
        );
    }

    public function testMalformedJsonFallsBackToPrioritySort(): void
    {
        $registry = $this->makeRegistry(
            sectionOrder: 'not valid json',
            itemOrder: 'also not valid',
        );

        self::assertSame(['crm', 'billing', 'notes'], $this->sectionIds($registry));
    }

    public function testNonStringEntriesAreFilteredOut(): void
    {
        // An admin shouldn't be able to inject non-string ids, but defend
        // anyway - the override may have been edited externally.
        $registry = $this->makeRegistry(
            sectionOrder: '["notes", 42, null, "crm"]',
            itemOrder: '{}',
        );

        self::assertSame(['notes', 'crm', 'billing'], $this->sectionIds($registry));
    }

    /** @return list<string> */
    private function sectionIds(ModuleRegistry $registry): array
    {
        return array_column($registry->getNavSections(), 'id');
    }

    private function makeRegistry(string $sectionOrder, string $itemOrder): ModuleRegistry
    {
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route): string => '/'.$route);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $settingRepository = $this->createMock(SettingRepository::class);
        $settingRepository->method('getOrDefault')->willReturnCallback(
            static fn ($key): string => match (true) {
                ApplicationParameterEnum::NavSectionOrder === $key || (is_object($key) && 'nav_section_order' === $key->value) => $sectionOrder,
                ApplicationParameterEnum::NavItemOrder === $key || (is_object($key) && 'nav_item_order' === $key->value) => $itemOrder,
                default => '',
            },
        );

        return new ModuleRegistry(
            modules: $this->makeModules(),
            security: $authChecker,
            urlGenerator: $urlGenerator,
            userSecurity: $security,
            settingRepository: $settingRepository,
        );
    }

    /** @return list<ModuleInterface> */
    private function makeModules(): array
    {
        return [
            new class implements ModuleInterface {
                public function getId(): string
                {
                    return 'crm';
                }

                public function getPermissions(): array
                {
                    return [];
                }

                public function getNavSections(): array
                {
                    return [new NavSection('crm', [
                        new NavItem('backend_crm_contacts', 'backend.nav.crm_contacts', 'users'),
                        new NavItem('backend_crm_companies', 'backend.nav.crm_companies', 'building'),
                        new NavItem('backend_crm_deals', 'backend.nav.crm_deals', 'handshake'),
                    ], priority: 40)];
                }

                public function getCatalogNavSections(): array
                {
                    return $this->getNavSections();
                }
            },
            new class implements ModuleInterface {
                public function getId(): string
                {
                    return 'billing';
                }

                public function getPermissions(): array
                {
                    return [];
                }

                public function getNavSections(): array
                {
                    return [new NavSection('billing', [
                        new NavItem('backend_billing_invoices', 'backend.nav.billing_invoices', 'file-text'),
                    ], priority: 50)];
                }

                public function getCatalogNavSections(): array
                {
                    return $this->getNavSections();
                }
            },
            new class implements ModuleInterface {
                public function getId(): string
                {
                    return 'notes';
                }

                public function getPermissions(): array
                {
                    return [];
                }

                public function getNavSections(): array
                {
                    return [new NavSection('notes', [
                        new NavItem('backend_notes', 'backend.nav.notes', 'sticky-note'),
                    ], priority: 60)];
                }

                public function getCatalogNavSections(): array
                {
                    return $this->getNavSections();
                }
            },
        ];
    }
}
