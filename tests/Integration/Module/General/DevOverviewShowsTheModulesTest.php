<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\General;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;

use function json_decode;

/**
 * La vue d'ensemble de l'administration montre les chiffres des modules.
 *
 * Elle demandait les statistiques d'une liste de modules vide, écrite du
 * temps où aucun module n'en fournissait : l'onglet annonçait toujours
 * « Aucun module de tableau de bord activé », alors que cinq en fournissent.
 */
final class DevOverviewShowsTheModulesTest extends IntegrationTestCase
{
    public function testTheOverviewCarriesTheEnabledModulesAndTheirFigures(): void
    {
        $client = static::createClient();
        $dev = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $dev);
        $client->loginUser($dev, 'admin');

        $client->request('GET', '/dev/dashboard', server: ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($payload['enabledModules']['editorial'] ?? false, 'the editorial module is on in the test install');
        self::assertArrayHasKey('editorial', $payload['stats'], 'and its figures come with it');
    }
}
