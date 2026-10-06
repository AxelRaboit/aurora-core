<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Dashboard;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Dashboard\StudioStatsProvider;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A tile on the dashboard follows its module's switch, as the menu does.
 *
 * The decks count used to show with decks switched off (they are
 * deliverables now, counted with them), and the contract counters with
 * contracts switched off: a figure that leads to a screen the menu no longer
 * offers.
 */
final class StudioStatsTogglesTest extends IntegrationTestCase
{
    private SettingRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->settings = $container->get(SettingRepository::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));
    }

    protected function tearDown(): void
    {
        $this->settings->set(ModuleParameterEnum::StudioDeliverables->value, '1');
        $this->settings->set(ModuleParameterEnum::StudioContracts->value, '1');

        parent::tearDown();
    }

    public function testTheTilesFollowTheirModulesSwitch(): void
    {
        $this->settings->set(ModuleParameterEnum::StudioDeliverables->value, '1');
        $this->settings->set(ModuleParameterEnum::StudioContracts->value, '1');

        $this->switched();
        $on = static::getContainer()->get(StudioStatsProvider::class)->getStats()['studio'];
        self::assertNotNull($on['deliverables']);
        self::assertNotNull($on['deliverablesPath']);
        self::assertArrayNotHasKey('decks', $on, 'presentations are counted with the deliverables');
        self::assertNotNull($on['awaitingSignature']);
        self::assertNotNull($on['contractsPath']);

        $this->settings->set(ModuleParameterEnum::StudioDeliverables->value, '0');
        $this->settings->set(ModuleParameterEnum::StudioContracts->value, '0');

        $this->switched();
        $off = static::getContainer()->get(StudioStatsProvider::class)->getStats()['studio'];
        self::assertNull($off['deliverables']);
        self::assertNull($off['deliverablesPath']);
        self::assertNull($off['awaitingSignature']);
        self::assertNull($off['contractsPath']);
    }

    /** The checker remembers what it read for the request; a test changes it mid-way. */
    private function switched(): void
    {
        static::getContainer()->get(ModuleAccessChecker::class)->reset();
    }
}
