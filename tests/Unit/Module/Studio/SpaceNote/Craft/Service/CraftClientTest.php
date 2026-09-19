<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\SpaceNote\Craft\Service;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\SpaceNote\Craft\Service\CraftClient;
use Aurora\Module\Studio\SpaceNote\Craft\Setting\CraftSettingEnum;
use Aurora\Module\Studio\SpaceNote\Craft\Setting\CraftSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function base64_decode;
use function base64_encode;

/**
 * Ce que le serveur demande à Craft, et ce qu'il fait de la réponse.
 *
 * Trois choses se passent mal en silence sur ce chemin : une connexion qui n'a
 * jamais été ouverte, une adresse en clair, et une réponse qui n'a pas la
 * forme attendue. Aucune des trois ne doit faire remonter une exception au
 * milieu de l'écran des notes d'un espace.
 */
final class CraftClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $calls = [];

    /**
     * Un vrai {@see CraftSettings} au-dessus d'un magasin en mémoire : la
     * classe est finale, et la construire pour de bon prouve au passage que le
     * client pose la bonne question - allumée, adressée et munie d'un jeton,
     * et pas seulement munie d'un jeton.
     */
    private function settings(string $endpoint, string $token, bool $enabled = true): CraftSettings
    {
        $store = [
            CraftSettingEnum::Enabled->value => $enabled ? '1' : '0',
            CraftSettingEnum::Endpoint->value => $endpoint,
            CraftSettingEnum::Token->value => '' === $token ? '' : base64_encode($token),
        ];

        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $store[$key] ?? $default,
        );
        $repository->method('getBoolean')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => '1' === ($store[$key] ?? ($default ? '1' : '0')),
        );

        $encryption = new class implements EncryptionServiceInterface {
            public function encrypt(string $plaintext): string
            {
                return base64_encode($plaintext);
            }

            public function decrypt(string $encoded): ?string
            {
                $decoded = base64_decode($encoded, strict: true);

                return false === $decoded ? null : $decoded;
            }
        };

        return new CraftSettings($repository, $encryption);
    }

    /** @param list<MockResponse> $responses */
    private function client(CraftSettings $settings, array $responses): CraftClient
    {
        $this->calls = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new CraftClient($http, new NullLogger(), $settings);
    }

    public function testNothingLeavesTheServerUntilTheConnectionIsOpened(): void
    {
        $client = $this->client($this->settings('https://connect.example/c/1', 'jeton', enabled: false), []);

        self::assertFalse($client->isConfigured());
        self::assertSame([], $client->documents());
        self::assertNull($client->markdown('abc'));
        self::assertSame([], $this->calls);
    }

    /** Un jeton parti en clair est un jeton lu par qui tient le réseau. */
    public function testAnAddressWithoutTlsCountsAsNoConnection(): void
    {
        $client = $this->client($this->settings('http://connect.example/c/1', 'jeton'), []);

        self::assertFalse($client->isConfigured());
        self::assertSame([], $this->calls);
    }

    public function testTheListIsAskedWithTheTokenAndSortedByTitle(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1/', 'jeton-secret'),
            [new MockResponse((string) json_encode(['documents' => [
                ['rootBlockId' => 'b', 'title' => 'Zèbre'],
                ['rootBlockId' => 'a', 'title' => 'Atelier'],
            ]]), ['response_headers' => ['content-type' => 'application/json']])],
        );

        $documents = $client->documents();

        self::assertSame([
            ['id' => 'a', 'title' => 'Atelier'],
            ['id' => 'b', 'title' => 'Zèbre'],
        ], $documents);

        // La barre finale de l'adresse est retirée : sans cela, `//documents`.
        self::assertSame('https://connect.example/c/1/documents', $this->calls[0]['url']);
        self::assertContains('Authorization: Bearer jeton-secret', $this->calls[0]['options']['headers']);
    }

    /**
     * `rootBlockId` et non `id` : c'est celui que `/blocks` attend, et
     * l'identifiant qu'une adresse de document affiche en est un autre.
     */
    public function testARowWithoutARootBlockIdIsSkipped(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse((string) json_encode(['documents' => [
                ['title' => 'Sans identifiant'],
                ['rootBlockId' => '', 'title' => 'Vide'],
                ['rootBlockId' => 'ok', 'title' => 'Bon'],
            ]]), ['response_headers' => ['content-type' => 'application/json']])],
        );

        self::assertSame([['id' => 'ok', 'title' => 'Bon']], $client->documents());
    }

    /** Un document sans titre reste choisissable, sous son identifiant. */
    public function testAnUntitledDocumentFallsBackToItsIdentifier(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse((string) json_encode([['rootBlockId' => 'xyz', 'title' => '  ']]), [
                'response_headers' => ['content-type' => 'application/json'],
            ])],
        );

        self::assertSame([['id' => 'xyz', 'title' => 'xyz']], $client->documents());
    }

    /**
     * C'est Craft qui rend le Markdown, parce que c'est lui qui connaît ses
     * blocs. L'en-tête est donc la moitié importante de cet appel.
     */
    public function testTheContentIsAskedAsMarkdown(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse("# Brief\n\nDu texte.")],
        );

        self::assertSame("# Brief\n\nDu texte.", $client->markdown('root-1'));
        self::assertStringContainsString('/blocks', $this->calls[0]['url']);
        self::assertStringContainsString('id=root-1', $this->calls[0]['url']);
        self::assertContains('Accept: text/markdown', $this->calls[0]['options']['headers']);
    }

    public function testAnEmptyAnswerIsNoAnswer(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse("   \n")],
        );

        self::assertNull($client->markdown('root-1'));
    }

    /**
     * Un écran qui ne propose rien est une déception ; une erreur 500 au
     * milieu d'un espace client en est une autre.
     */
    public function testAServerErrorBecomesAnEmptyListRatherThanAnException(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse('nope', ['http_code' => 503])],
        );

        self::assertSame([], $client->documents());
        self::assertNull($this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse('nope', ['http_code' => 503])],
        )->markdown('root-1'));
    }

    public function testAnAnswerThatIsNotJsonBecomesAnEmptyList(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse('<html>connexion expirée</html>', [
                'response_headers' => ['content-type' => 'text/html'],
            ])],
        );

        self::assertSame([], $client->documents());
    }
}
