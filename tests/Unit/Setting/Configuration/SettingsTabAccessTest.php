<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Setting\Configuration;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;
use Aurora\Module\Configuration\Setting\Configuration\SettingDefinitionRegistry;
use Aurora\Module\Configuration\Setting\Configuration\SettingFieldDescriptor;
use Aurora\Module\Configuration\Setting\Configuration\SettingsTabAccess;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who may see which settings tab.
 *
 * The rule used to live inside `SettingsViewBuilder`, where the only thing it
 * could do was leave a tab out of the payload - a decision the browser then
 * had to respect. Now that a tab is a URL, this is what a 404 is decided from,
 * so it is worth its own tests.
 */
#[AllowMockObjectsWithoutExpectations]
final class SettingsTabAccessTest extends TestCase
{
    public function testTabsComeBackInPriorityOrder(): void
    {
        $access = $this->makeAccess([
            $this->tab('seo', 60),
            $this->tab('general', 10),
            $this->tab('email', 80),
        ]);

        self::assertSame(
            ['general', 'seo', 'email'],
            array_map(static fn (ConfigurationTab $tab): string => $tab->id, $access->visibleTabs()),
        );
    }

    public function testADevOnlyTabIsHiddenFromAnAdmin(): void
    {
        $access = $this->makeAccess(
            [$this->tab('general', 10), $this->tab('sequences', 90, devOnly: true)],
            granted: ['ROLE_DEV' => false],
        );

        self::assertSame(['general'], $this->ids($access));
        self::assertFalse($access->isVisibleId('sequences'));
    }

    public function testADevOnlyTabIsVisibleToADev(): void
    {
        $access = $this->makeAccess(
            [$this->tab('sequences', 90, devOnly: true)],
            granted: ['ROLE_DEV' => true],
        );

        self::assertTrue($access->isVisibleId('sequences'));
    }

    public function testATabPrivilegeIsEnforced(): void
    {
        $access = $this->makeAccess(
            [
                $this->tab('general', 10),
                $this->tab('billing', 50, requiredPrivilege: 'billing.settings.manage'),
            ],
            granted: ['billing.settings.manage' => false],
        );

        self::assertSame(['general'], $this->ids($access));
    }

    public function testATabWhoseModuleIsOffIsHidden(): void
    {
        $access = $this->makeAccess(
            [$this->tab('notes', 45, moduleToggle: 'modules_notes_backend')],
            moduleEnabled: false,
        );

        self::assertSame([], $this->ids($access));
    }

    // `alwaysVisible` is how a tab whose body is a Vue component says it has
    // something to draw. Without the flag, an empty field list means an empty
    // page, and the tab has no business being offered.
    public function testAnEmptyTabWithNoComponentIsNotOffered(): void
    {
        $access = $this->makeAccess([
            new ConfigurationTab(id: 'hollow', priority: 10, fields: []),
            $this->tab('navigation', 100, alwaysVisible: true),
        ]);

        self::assertSame(['navigation'], $this->ids($access));
    }

    public function testFirstVisibleIsTheFirstThisUserMaySee(): void
    {
        // `general` is dev-only here, so an admin's first tab is `seo` - which is
        // why the landing tab cannot be hardcoded.
        $access = $this->makeAccess(
            [$this->tab('general', 10, devOnly: true), $this->tab('seo', 60)],
            granted: ['ROLE_DEV' => false],
        );

        self::assertSame('seo', $access->firstVisibleId());
    }

    public function testNoVisibleTabAtAllIsReported(): void
    {
        $access = $this->makeAccess(
            [$this->tab('general', 10, devOnly: true)],
            granted: ['ROLE_DEV' => false],
        );

        self::assertNull($access->firstVisibleId());
    }

    public function testFindReturnsTheTabItself(): void
    {
        $access = $this->makeAccess([$this->tab('general', 10)]);

        self::assertSame('general', $access->find('general')?->id);
        self::assertNull($access->find('nope'));
    }

    /** @return list<string> */
    private function ids(SettingsTabAccess $access): array
    {
        return array_map(static fn (ConfigurationTab $tab): string => $tab->id, $access->visibleTabs());
    }

    private function tab(
        string $id,
        int $priority,
        bool $devOnly = false,
        bool $alwaysVisible = false,
        ?string $requiredPrivilege = null,
        ModuleParameterEnum|string|null $moduleToggle = null,
    ): ConfigurationTab {
        return new ConfigurationTab(
            id: $id,
            priority: $priority,
            fields: [new SettingFieldDescriptor(key: $id.'_field', type: 'text', labelKey: 'l', descriptionKey: 'd', defaultValue: '')],
            alwaysVisible: $alwaysVisible,
            devOnly: $devOnly,
            moduleToggle: $moduleToggle,
            requiredPrivilege: $requiredPrivilege,
        );
    }

    /**
     * @param list<ConfigurationTab> $tabs
     * @param array<string, bool>    $granted attribute → verdict; anything unlisted is granted
     */
    private function makeAccess(array $tabs, array $granted = [], bool $moduleEnabled = true): SettingsTabAccess
    {
        $security = $this->createMock(AuthorizationCheckerInterface::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => $granted[(string) $attribute] ?? true,
        );

        $moduleAccessChecker = $this->createMock(ModuleAccessChecker::class);
        $moduleAccessChecker->method('isEnabled')->willReturn($moduleEnabled);

        return new SettingsTabAccess(
            new SettingDefinitionRegistry([new StubTabProvider($tabs)]),
            $moduleAccessChecker,
            $security,
        );
    }
}

final readonly class StubTabProvider implements ConfigurationTabProviderInterface
{
    /** @param list<ConfigurationTab> $tabs */
    public function __construct(private array $tabs) {}

    public function getTabs(): array
    {
        return $this->tabs;
    }
}
