<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Core\Version;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The version a suite page compares itself with, to offer a reload after a
 * deployment.
 */
final class VersionControllerTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
    }

    public function testASignedInPageReadsTheVersionTheServerRuns(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', '/suite/version', server: ['HTTP_ACCEPT' => 'application/json']);

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsString($body['version'] ?? null);
        self::assertNotSame('', $body['version']);
        // An answer kept from before the deployment would say nothing changed.
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testNobodyElseIsTold(): void
    {
        $this->client->request('GET', '/suite/version', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertStringNotContainsString('"version"', (string) $this->client->getResponse()->getContent());
    }

    /** The page carries what it compares with, and where to ask. */
    public function testASuitePageCarriesItsVersionAndWhereToAsk(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', '/suite');

        $body = html_entity_decode((string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('AppVersionWatch', $body);
        self::assertStringContainsString('"versionPath":"\/suite\/version"', $body);
    }
}
