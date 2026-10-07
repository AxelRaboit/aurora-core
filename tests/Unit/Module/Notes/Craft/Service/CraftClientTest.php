<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes\Craft\Service;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Craft\Service\CraftClient;
use Aurora\Module\Notes\Craft\Setting\CraftSettingEnum;
use Aurora\Module\Notes\Craft\Setting\CraftSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function base64_decode;
use function base64_encode;

/**
 * What the server asks Craft, and what it does with the answer.
 *
 * Three things go wrong silently on this path: a connection that was never
 * opened, a plain-text address, and a response that does not have the
 * expected shape. None of the three may raise an exception in the middle of
 * a space's notes screen.
 */
final class CraftClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $calls = [];

    /**
     * A real {@see CraftSettings} on top of an in-memory store: the class is
     * final, and building it for real also proves that the client asks the
     * right question - enabled, addressed and holding a token, and not just
     * holding a token.
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
        self::assertNull($client->documents());
        self::assertNull($client->markdown('abc'));
        self::assertSame([], $this->calls);
    }

    /** A token sent in clear text is a token read by whoever holds the network. */
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
            [new MockResponse((string) json_encode(['items' => [
                ['rootBlockId' => 'b', 'title' => 'Zèbre'],
                ['rootBlockId' => 'a', 'title' => 'Atelier'],
            ]]), ['response_headers' => ['content-type' => 'application/json']])],
        );

        $documents = $client->documents();

        self::assertSame([
            ['id' => 'a', 'title' => 'Atelier'],
            ['id' => 'b', 'title' => 'Zèbre'],
        ], $documents);

        // The trailing slash of the address is removed: otherwise, `//documents`.
        self::assertSame('https://connect.example/c/1/documents', $this->calls[0]['url']);
        self::assertContains('Authorization: Bearer jeton-secret', $this->calls[0]['options']['headers']);
    }

    /**
     * `rootBlockId` and not `id`: it is the one `/blocks` expects, and the id
     * a document address shows is yet another one.
     */
    public function testARowWithoutAnIdOrAliveDocumentIsSkipped(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse((string) json_encode(['items' => [
                ['title' => 'Sans identifiant'],
                ['id' => '', 'title' => 'Vide'],
                ['id' => 'jete', 'title' => 'Document supprimé', 'isDeleted' => true],
                ['id' => 'ok', 'title' => 'Bon'],
            ]]), ['response_headers' => ['content-type' => 'application/json']])],
        );

        self::assertSame([['id' => 'ok', 'title' => 'Bon']], $client->documents());
    }

    /** A document without a title can still be chosen, under its id. */
    public function testAnUntitledDocumentFallsBackToItsIdentifier(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse((string) json_encode(['items' => [['id' => 'xyz', 'title' => '  ']]]), [
                'response_headers' => ['content-type' => 'application/json'],
            ])],
        );

        self::assertSame([['id' => 'xyz', 'title' => 'xyz']], $client->documents());
    }

    /**
     * Craft renders the Markdown, because it is the one that knows its
     * blocks. The header is therefore the important half of this call.
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
     * A screen that offers nothing is a letdown; a 500 error in the middle of
     * a client space is another one.
     */
    /**
     * Nothing heard, not nothing to say: the two look alike on screen and are
     * not fixed in the same place.
     */
    public function testAServerErrorIsToldApartFromAnEmptyConnection(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse('nope', ['http_code' => 503])],
        );

        self::assertNull($client->documents());

        $empty = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse((string) json_encode(['items' => []]), [
                'response_headers' => ['content-type' => 'application/json'],
            ])],
        );

        self::assertSame([], $empty->documents());
        self::assertNull($this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse('nope', ['http_code' => 503])],
        )->markdown('root-1'));
    }

    public function testAnAnswerThatIsNotJsonIsNoAnswerAtAll(): void
    {
        $client = $this->client(
            $this->settings('https://connect.example/c/1', 'jeton'),
            [new MockResponse('<html>connexion expirée</html>', [
                'response_headers' => ['content-type' => 'text/html'],
            ])],
        );

        self::assertNull($client->documents());
    }
}
