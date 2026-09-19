<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\SpaceFile\GoogleDrive;

use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveClient;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;

/**
 * Ce que le serveur demande au Drive, et ce qu'il fait des réponses.
 *
 * Trois choses se passent mal en silence : un dossier partagé depuis un Drive
 * partagé qui rend une liste vide sans erreur, une corbeille qui ressort comme
 * un fichier vivant, et un jeton d'échec gardé une heure. Aucune ne doit faire
 * remonter une exception au milieu d'un espace client.
 */
final class DriveClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $calls = [];

    private GoogleServiceAccount $account;

    protected function setUp(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);

        $private = '';
        openssl_pkey_export($resource, $private);

        $account = GoogleServiceAccount::fromJson((string) json_encode([
            'type' => 'service_account',
            'client_email' => 'aurora@projet.iam.gserviceaccount.com',
            'private_key' => $private,
        ]));

        self::assertNotNull($account);
        $this->account = $account;
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): DriveClient
    {
        $this->calls = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new DriveClient($http, new ArrayAdapter(), new NullLogger());
    }

    private function token(): MockResponse
    {
        return new MockResponse((string) json_encode(['access_token' => 'jeton-google', 'expires_in' => 3600]), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /** @param list<array<string, mixed>> $files */
    private function listing(array $files): MockResponse
    {
        return new MockResponse((string) json_encode(['files' => $files]), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    public function testTheTokenIsExchangedThenUsedAsABearer(): void
    {
        $client = $this->client([$this->token(), $this->listing([])]);

        $client->files($this->account, 'dossier-1');

        self::assertSame(GoogleServiceAccount::TOKEN_URI, $this->calls[0]['url']);
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertContains('Authorization: Bearer jeton-google', $this->calls[1]['options']['headers']);
    }

    /** Un jeton vaut une heure : le redemander à chaque page est un aller-retour pour rien. */
    public function testTheTokenIsAskedOnceAndReused(): void
    {
        $client = $this->client([$this->token(), $this->listing([]), $this->listing([])]);

        $client->files($this->account, 'dossier-1');
        $client->files($this->account, 'dossier-2');

        self::assertCount(3, $this->calls);
        self::assertSame(GoogleServiceAccount::TOKEN_URI, $this->calls[0]['url']);
        self::assertStringContainsString('/drive/v3/files', $this->calls[1]['url']);
        self::assertStringContainsString('/drive/v3/files', $this->calls[2]['url']);
    }

    /**
     * La corbeille d'un Drive reste dans le dossier, et un sous-dossier n'est
     * pas un fichier : les deux ressortiraient comme des pièces jointes.
     */
    public function testTheQueryExcludesTheTrashAndTheFolders(): void
    {
        $client = $this->client([$this->token(), $this->listing([])]);

        $client->files($this->account, 'dossier-1');

        $query = $this->calls[1]['options']['query'];

        self::assertStringContainsString("'dossier-1' in parents", $query['q']);
        self::assertStringContainsString('trashed = false', $query['q']);
        self::assertStringContainsString('application/vnd.google-apps.folder', $query['q']);
    }

    /**
     * Sans ces deux drapeaux, un dossier partagé depuis un Drive partagé rend
     * une liste vide - et rend une liste vide sans dire pourquoi.
     */
    public function testSharedDrivesAreIncluded(): void
    {
        $client = $this->client([$this->token(), $this->listing([])]);

        $client->files($this->account, 'dossier-1');

        $query = $this->calls[1]['options']['query'];

        self::assertSame('true', $query['supportsAllDrives']);
        self::assertSame('true', $query['includeItemsFromAllDrives']);
    }

    /** Un dossier partagé se lit par ce qui vient d'y arriver. */
    public function testFilesComeBackNewestFirst(): void
    {
        $client = $this->client([$this->token(), $this->listing([
            ['id' => 'a', 'name' => 'Ancien', 'mimeType' => 'image/png', 'size' => '12', 'modifiedTime' => '2026-01-01T00:00:00Z'],
            ['id' => 'b', 'name' => 'Récent', 'mimeType' => 'image/png', 'size' => '34', 'modifiedTime' => '2026-09-01T00:00:00Z'],
        ])]);

        $files = $client->files($this->account, 'dossier-1');

        self::assertSame(['Récent', 'Ancien'], array_column($files, 'name'));
        self::assertSame(34, $files[0]['size']);
    }

    /** Un document Google n'a pas d'octets tant qu'on ne l'a pas exporté. */
    public function testAFileWithoutASizeIsStillListed(): void
    {
        $client = $this->client([$this->token(), $this->listing([
            ['id' => 'a', 'name' => 'Le brief', 'mimeType' => 'application/vnd.google-apps.document'],
        ])]);

        $files = $client->files($this->account, 'dossier-1');

        self::assertCount(1, $files);
        self::assertNull($files[0]['size']);
    }

    public function testAServerErrorBecomesAnEmptyListRatherThanAnException(): void
    {
        $client = $this->client([$this->token(), new MockResponse('nope', ['http_code' => 403])]);

        self::assertSame([], $client->files($this->account, 'dossier-1'));
    }

    /** Une clé qu'on vient de corriger doit marcher tout de suite. */
    public function testARefusedTokenIsNotKeptForAnHour(): void
    {
        $client = $this->client([
            new MockResponse((string) json_encode(['error' => 'invalid_grant']), [
                'response_headers' => ['content-type' => 'application/json'],
            ]),
            $this->token(),
            $this->listing([['id' => 'a', 'name' => 'Enfin', 'mimeType' => 'image/png']]),
        ]);

        self::assertSame([], $client->files($this->account, 'dossier-1'));
        self::assertSame(['Enfin'], array_column($client->files($this->account, 'dossier-1'), 'name'));
    }

    /** Un fichier retiré du partage n'est pas une erreur, c'est un message. */
    public function testARefusedDownloadIsNull(): void
    {
        $client = $this->client([$this->token(), new MockResponse('', ['http_code' => 404])]);

        self::assertNull($client->download($this->account, 'fichier-parti'));
    }

    /**
     * Le flux est rendu tel quel : mettre une vidéo de cinquante mégaoctets
     * dans une chaîne PHP pour la recracher ferait tomber le serveur.
     */
    public function testADownloadComesBackAsAStreamNotAsAString(): void
    {
        $client = $this->client([$this->token(), new MockResponse('des octets', [
            'response_headers' => ['content-type' => 'image/png'],
        ])]);

        $response = $client->download($this->account, 'fichier-1');

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('media', $this->calls[1]['options']['query']['alt']);
        self::assertFalse($this->calls[1]['options']['buffer']);
    }
}
