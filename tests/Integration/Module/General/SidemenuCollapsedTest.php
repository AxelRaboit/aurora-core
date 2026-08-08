<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\General;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Whether the sidemenu is folded now belongs to the user, beside the sections
 * they hid and the colours they picked.
 *
 * The part worth pinning is not the column but the render: the layout has to
 * emit the class itself, or the menu starts expanded on every page and snaps
 * shut once a script has run — which is what storing it in the browser could
 * never avoid.
 */
final class SidemenuCollapsedTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $user);

        $this->user = $user;
        $this->client->loginUser($user, 'admin');
    }

    public function testFoldingIsRememberedOnTheAccount(): void
    {
        $this->post(true);

        self::assertTrue($this->reload()->isSidemenuCollapsed());

        $this->post(false);

        self::assertFalse($this->reload()->isSidemenuCollapsed());
    }

    /**
     * The whole reason it moved out of the browser: a class a script adds
     * arrives after the first paint, so the menu is visibly wide for a frame.
     */
    public function testTheLayoutRendersTheClassRatherThanLettingAScriptAddIt(): void
    {
        $this->post(true);

        $this->client->request('GET', '/backend/general/profile');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'class="sidemenu-collapsed"',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testAnExpandedMenuLeavesTheMarkupAlone(): void
    {
        $this->post(false);

        $this->client->request('GET', '/backend/general/profile');

        self::assertStringNotContainsString(
            'sidemenu-collapsed',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** A body that says nothing means expanded, not a 500. */
    public function testAnEmptyPayloadIsReadAsExpanded(): void
    {
        $this->post(true);

        $this->client->request(
            'POST',
            '/backend/general/profile/sidemenu/collapsed',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->reload()->isSidemenuCollapsed());
    }

    private function post(bool $collapsed): void
    {
        $this->client->request(
            'POST',
            '/backend/general/profile/sidemenu/collapsed',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['collapsed' => $collapsed], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function reload(): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $user = static::getContainer()->get(UserRepository::class)->find($this->user->getId());
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
