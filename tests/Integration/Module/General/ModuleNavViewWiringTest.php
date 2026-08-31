<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\General;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The side menu's second view reaches the component from the server.
 *
 * Worth pinning because the alternative is invisible until someone opens a
 * module: if `module_nav_view()` stopped being handed to `AppSidemenu`, the
 * project view would keep rendering perfectly and nothing else would fail. The
 * column would simply never switch again.
 *
 * The prop is asserted, never its value. A value assertion would go red the day
 * a module opts into `ModuleNavViewProviderInterface` - which is the next step,
 * not a regression.
 */
final class ModuleNavViewWiringTest extends IntegrationTestCase
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

    public function testTheMenuIsHandedTheModuleViewForTheCurrentRoute(): void
    {
        $this->client->request('GET', '/backend/general/profile');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'moduleNavView',
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
