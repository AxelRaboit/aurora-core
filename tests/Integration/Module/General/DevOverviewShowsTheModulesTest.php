<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\General;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;

use function json_decode;

/**
 * The administration overview shows the modules' figures.
 *
 * It asked for the statistics of an empty module list, written back when no
 * module provided any: the tab always announced "Aucun module de tableau de
 * bord activé", while five provide some.
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
