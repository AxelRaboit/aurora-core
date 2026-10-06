<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Module\Studio\Sharing\ShareToken;
use Aurora\Tests\Integration\IntegrationTestCase;

/**
 * A presentation address shared before the move to deliverables.
 *
 * The migration kept each link's token: `/decks/{jeton}` must really lead to
 * `/deliverables/{jeton}`, where the reading page judges the token. What
 * would break silently: a client who opens the link received last month and
 * lands on a page that cannot be found.
 */
final class LegacyDeckLinkTest extends IntegrationTestCase
{
    public function testADeckAddressMovesForGoodToTheReadingPage(): void
    {
        $client = self::createClient();
        $token = ShareToken::generate();

        $client->request('GET', '/decks/'.$token);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/deliverables/'.$token);
        self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testThePasswordFormOfAnOldPageFollows(): void
    {
        $client = self::createClient();
        $token = ShareToken::generate();

        $client->request('POST', '/decks/'.$token.'/unlock', ['password' => 'peu importe']);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/deliverables/'.$token);
    }

    public function testAnAddressThatIsNotATokenIsNotFound(): void
    {
        $client = self::createClient();

        $client->request('GET', '/decks/fonts');

        self::assertResponseStatusCodeSame(404);
    }
}
