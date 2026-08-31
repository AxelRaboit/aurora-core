<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Configuration;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A settings tab is an address now.
 *
 * It used to be a fragment: `#seo` was the tab's only identity, which is why it
 * could not be linked to, could not carry a breadcrumb, made no history entry
 * and was invisible to the search palette. What follows is what a fragment could
 * not have been asked to prove - the server answers for the tab.
 */
final class SettingsTabRoutingTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $user);

        $this->client->loginUser($user, 'admin');
    }

    /**
     * A redirect, not a render: rendering here as well would give the first tab
     * two addresses, and the side menu could not tell which of them it is on.
     */
    public function testTheBareSettingsUrlRedirectsToTheFirstTab(): void
    {
        $this->client->request('GET', '/backend/configuration/settings');

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertStringContainsString(
            '/backend/configuration/settings/',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    public function testATabRendersAndNamesItselfToTheComponent(): void
    {
        $this->client->request('GET', '/backend/configuration/settings/seo');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('activeTab', $content);
        self::assertStringContainsString('seo', $content);
    }

    /**
     * The breadcrumb can name the tab, which is the whole point of it having an
     * address. `Réglages` sits above it and leads back to the first tab.
     */
    public function testTheBreadcrumbNamesTheTab(): void
    {
        $crawler = $this->client->request('GET', '/backend/configuration/settings/seo');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('SEO', $crawler->filter('header')->text());
    }

    public function testAnUnknownTabIsNotFound(): void
    {
        $this->client->request('GET', '/backend/configuration/settings/definitely_not_a_tab');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The write endpoint sits under the same prefix as the tab parameter. A GET
     * on it must not be swallowed into a rendered tab - which is what a
     * `/{tab}` route declared without care would do.
     */
    public function testTheUpdateEndpointIsNotMistakenForATab(): void
    {
        $this->client->request('GET', '/backend/configuration/settings/update');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The tab the side menu draws and the tab the URL names are the same thing.
     * Before, the menu had one entry for the whole page and the tab lived in the
     * fragment; a mismatch was not expressible, and neither was agreement.
     */
    public function testTheSideMenuIsHandedConfigurationsOwnView(): void
    {
        $this->client->request('GET', '/backend/configuration/settings/seo');

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('moduleNavView', $content);
        self::assertStringContainsString('configuration.settings.tab.seo', $content);
    }
}
