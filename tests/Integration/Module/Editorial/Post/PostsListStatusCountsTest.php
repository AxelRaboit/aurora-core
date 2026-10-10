<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;

/**
 * The posts list carries one figure per status, for the switch above it.
 *
 * Counted over everything the reader may see and nothing narrower: a search
 * or a type filter changes the rows, not the figures on the tabs, or a tab
 * would read zero because of a word typed elsewhere.
 */
final class PostsListStatusCountsTest extends IntegrationTestCase
{
    public function testTheListCarriesAFigurePerStatus(): void
    {
        $client = static::createClient();
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $client->loginUser($admin, 'admin');

        $client->request('GET', '/suite/editorial/posts?search=zzzz-nothing-matches', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $payload['total'], 'The search narrows the rows.');
        self::assertSame(
            static::getContainer()->get(PostRepository::class)->countByStatus(),
            $payload['statusCounts'],
            'A developer sees every post, and the figures ignore the search.',
        );
    }
}
