<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\EventSubscriber;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The parameter existed for months while nothing read it, so these tests are
 * written to fail if it ever goes back to being decorative: each one asserts
 * on the status code the visitor actually receives.
 *
 * One request per test method - the kernel reboots between requests, which is
 * what makes the freshly written setting visible to SettingRepository's
 * per-request cache.
 */
final class MaintenanceModeTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        $this->setMaintenance(false);
        parent::tearDown();
    }

    public function testPublicSiteIsServedWhenMaintenanceIsOff(): void
    {
        $this->setMaintenance(false);

        $this->client->request('GET', '/fr');

        self::assertNotSame(503, $this->client->getResponse()->getStatusCode());
    }

    public function testPublicSiteAnswersServiceUnavailableWhenMaintenanceIsOn(): void
    {
        $this->setMaintenance(true);

        $this->client->request('GET', '/fr');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('3600', $response->headers->get('Retry-After'));
    }

    /**
     * The one that matters: closing the public site must never close the
     * screen that reopens it.
     */
    public function testBackendStaysReachableWhenMaintenanceIsOn(): void
    {
        $this->setMaintenance(true);

        $this->client->request('GET', '/backend/platform/login');

        self::assertNotSame(503, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The maintenance page loads its stylesheet from /build; serving it a 503
     * would leave visitors with unstyled text.
     */
    public function testBuiltAssetsAreNotClosed(): void
    {
        $this->setMaintenance(true);

        $this->client->request('GET', '/build/assets/does-not-exist.js');

        self::assertNotSame(503, $this->client->getResponse()->getStatusCode());
    }

    private function setMaintenance(bool $enabled): void
    {
        static::getContainer()
            ->get(SettingRepository::class)
            ->set(ApplicationParameterEnum::MaintenanceMode->value, $enabled ? '1' : '0');
    }
}
