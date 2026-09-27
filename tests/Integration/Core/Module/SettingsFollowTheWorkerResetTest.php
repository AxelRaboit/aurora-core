<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Core\Module;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;

/**
 * A worker sees a setting changed while it runs, from its next message on.
 *
 * The settings and the module toggles are kept in memory once read. Nothing
 * emptied that memory between two messages, so a worker kept the settings of
 * the moment it started: switching the Planning off did not stop its reminders
 * until somebody restarted the worker. Symfony's resetter runs between
 * messages; both caches now answer to it.
 */
final class SettingsFollowTheWorkerResetTest extends IntegrationTestCase
{
    private const string KEY = ModuleParameterEnum::PlanningBackend->value;

    private string|false $before = false;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->before = static::getContainer()->get(Connection::class)->fetchOne('SELECT value FROM core_settings WHERE setting_key = ?', [self::KEY]);
    }

    protected function tearDown(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        false === $this->before
            ? $connection->executeStatement('DELETE FROM core_settings WHERE setting_key = ?', [self::KEY])
            : $this->write((string) $this->before);

        parent::tearDown();
    }

    public function testATogglePutOffWhileTheWorkerRunsIsSeenAfterTheReset(): void
    {
        $checker = static::getContainer()->get(ModuleAccessChecker::class);
        $settings = static::getContainer()->get(SettingRepository::class);
        $settings->set(self::KEY, '1');
        self::assertTrue($checker->isGloballyEnabled(self::KEY));
        self::assertSame('1', $settings->get(self::KEY));

        // Another process switches the module off.
        $this->write('0');
        self::assertTrue($checker->isGloballyEnabled(self::KEY), 'within one message the memory holds');

        static::getContainer()->get('services_resetter')->reset();

        self::assertSame('0', $settings->get(self::KEY));
        self::assertFalse($checker->isGloballyEnabled(self::KEY), 'the next message sees the change');
    }

    private function write(string $value): void
    {
        static::getContainer()->get(Connection::class)->executeStatement('UPDATE core_settings SET value = ? WHERE setting_key = ?', [$value, self::KEY]);
    }
}
