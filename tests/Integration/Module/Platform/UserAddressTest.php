<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Platform;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A user has an address.
 *
 * It was a modal over a table and nothing else: it could not be sent to a
 * colleague, carried no breadcrumb and was invisible to the palette. The same
 * address already existed as a JSON endpoint for the page's own calls, so the
 * two answers share one route and are told apart by `X-Requested-With` - which
 * is exactly why `convention_no_raw_fetch` exists.
 */
final class UserAddressTest extends IntegrationTestCase
{
    private KernelBrowser $client;
    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $user);

        $this->client->loginUser($user, 'admin');
        $this->target = $user;
    }

    public function testTheAddressServesThePageToABrowser(): void
    {
        $this->client->request('GET', '/backend/platform/users/'.$this->target->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('platform/backend/users/UsersApp', $content);
        self::assertStringContainsString(
            '&quot;activeId&quot;:'.$this->target->getId(),
            $content,
            'the page is told which user the address names',
        );
    }

    /** The page's own calls still get the record, unchanged. */
    public function testTheSameAddressStillServesJsonToThePage(): void
    {
        $this->client->request(
            'GET',
            '/backend/platform/users/'.$this->target->getId(),
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertSame($this->target->getId(), $payload['user']['id']);
    }

    /**
     * The listing is not a redirect to a first record, unlike Editorial's: a
     * user list is a table you browse and filter, and the record is what you
     * open from it.
     */
    public function testTheListingStaysADestinationOfItsOwn(): void
    {
        $this->client->request('GET', '/backend/platform/users');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
