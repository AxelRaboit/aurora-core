<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The three Studio screens open.
 *
 * The dullest test in the module, and the one it needed most. Every other test
 * here drives the endpoints - create, seal, send, sign - and two of them fetch
 * a document page by its real id. None of them ever asked for a list, so for
 * eleven releases the three index screens answered 500 outside production and
 * nobody found out: the view builders generated `/contracts/__id__/update`
 * from a route declared `\d+`, which the URL generator refuses while the page
 * is still rendering.
 *
 * Production hid it, and that is the part worth remembering. There
 * `strict_requirements` is `null`, so the hole is punched without complaint -
 * the defect existed only where the developers were, which is why it survived
 * so long and why the module has no screenshot of itself.
 *
 * So: a request per screen, an assertion on the status code, and nothing else.
 * A screen that cannot be opened has no features.
 */
final class StudioScreensTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        $this->client->loginUser($admin, 'admin');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function screenProvider(): iterable
    {
        yield 'les clients' => ['/suite/studio/customers'];
        yield 'les trames de contrat' => ['/suite/studio/contract-templates'];
        yield 'les contrats' => ['/suite/studio/contracts'];
    }

    #[DataProvider('screenProvider')]
    public function testTheScreenRenders(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
    }
}
