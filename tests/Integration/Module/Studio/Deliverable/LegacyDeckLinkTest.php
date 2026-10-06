<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Module\Studio\Sharing\ShareToken;
use Aurora\Tests\Integration\IntegrationTestCase;

/**
 * Une adresse de présentation partagée avant le passage aux livrables.
 *
 * La migration a gardé le jeton de chaque lien : `/decks/{jeton}` doit mener
 * pour de bon à `/deliverables/{jeton}`, où la page de lecture juge le jeton.
 * Ce qui se casserait sans bruit : un client qui ouvre le lien reçu le mois
 * dernier et tombe sur une page introuvable.
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
