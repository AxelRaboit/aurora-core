<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Core;

use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Links sent before the suite moved from `/backend` to `/suite` - invitations,
 * password resets, bookmarks - still land on their page.
 */
final class LegacySuitePathRedirectTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    public function testAnOldAddressIsSentToTheSamePageUnderSuite(): void
    {
        $this->client->request('GET', '/backend/platform/reset-password?token=abc&x=1');

        self::assertResponseStatusCodeSame(308);
        self::assertResponseRedirects('/suite/platform/reset-password?token=abc&x=1', 308);
    }

    public function testTheBareOldPrefixGoesToTheSuite(): void
    {
        $this->client->request('GET', '/backend');

        self::assertResponseRedirects('/suite', 308);
    }

    public function testAFormPostedFromAnOldTabKeepsItsMethod(): void
    {
        $this->client->request('POST', '/backend/platform/login', ['_username' => 'nobody']);

        // 308 tells the browser to repeat the POST, body included, at the new address.
        self::assertResponseRedirects('/suite/platform/login', 308);
    }

    public function testAWordThatOnlyStartsLikeTheOldPrefixIsLeftAlone(): void
    {
        $this->client->request('GET', '/backendish');

        self::assertFalse($this->client->getResponse()->isRedirect());
    }

    public function testTheNewAddressAnswers(): void
    {
        $this->client->request('GET', '/suite/platform/login');

        self::assertResponseIsSuccessful();
    }
}
