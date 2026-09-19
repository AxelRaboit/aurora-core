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

    /** @return array<string, mixed> */
    private function file(string $id, string $name, string $parent): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'mimeType' => 'application/pdf',
            'size' => '10',
            'modifiedTime' => '2026-09-01T00:00:00Z',
            'parents' => [$parent],
        ];
    }

    /** @return array<string, mixed> */
    private function folder(string $id, string $name, string $parent): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parent],
        ];
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

    /** La corbeille reste dans le dossier et ressortirait comme un fichier. */
    public function testTheQueryExcludesTheTrash(): void
    {
        $client = $this->client([$this->token(), $this->listing([])]);

        $client->files($this->account, 'dossier-1');

        $query = $this->calls[1]['options']['query'];

        self::assertStringContainsString("'dossier-1' in parents", $query['q']);
        self::assertStringContainsString('trashed = false', $query['q']);
        // Les dossiers ne sont plus exclus : c'est par eux qu'on descend.
        self::assertStringNotContainsString('mimeType !=', $query['q']);
    }

    /**
     * **Un appel par étage, pas par dossier.** Descendre dossier par dossier
     * aurait fait une requête chacun ; ce test est la seule chose qui
     * empêchera quelqu'un de réécrire la boucle de la façon évidente.
     */
    public function testAWholeLevelIsAskedInOneCall(): void
    {
        $client = $this->client([
            $this->token(),
            $this->listing([
                $this->folder('d-a', 'Contrats', 'racine'),
                $this->folder('d-b', 'Visuels', 'racine'),
            ]),
            $this->listing([
                $this->file('f-1', 'bail.pdf', 'd-a'),
                $this->file('f-2', 'logo.png', 'd-b'),
            ]),
        ]);

        $files = $client->files($this->account, 'racine');

        // Le jeton, la racine, puis les deux dossiers ensemble : trois appels
        // et non quatre.
        self::assertCount(3, $this->calls);
        self::assertStringContainsString("'d-a' in parents or 'd-b' in parents", $this->calls[2]['options']['query']['q']);
        self::assertCount(2, $files);
    }

    /** Une liste plate sans dire d'où vient chaque fichier serait illisible. */
    public function testEachFileCarriesTheFolderItCameFrom(): void
    {
        $client = $this->client([
            $this->token(),
            $this->listing([
                $this->file('f-0', 'a-la-racine.pdf', 'racine'),
                $this->folder('d-a', 'Contrats', 'racine'),
            ]),
            $this->listing([$this->folder('d-b', '2026', 'd-a')]),
            $this->listing([$this->file('f-1', 'bail.pdf', 'd-b')]),
        ]);

        $files = $client->files($this->account, 'racine');
        $paths = array_combine(array_column($files, 'name'), array_column($files, 'path'));

        self::assertSame('', $paths['a-la-racine.pdf']);
        self::assertSame('Contrats/2026', $paths['bail.pdf']);
    }

    /** Un raccourci circulaire ferait tourner la descente sans fin. */
    public function testAFolderThatPointsBackAtItselfDoesNotLoop(): void
    {
        $client = $this->client([
            $this->token(),
            $this->listing([$this->folder('d-a', 'Boucle', 'racine')]),
            // Le même dossier, remonté par lui-même.
            $this->listing([$this->folder('d-a', 'Boucle', 'd-a'), $this->file('f-1', 'seul.pdf', 'd-a')]),
        ]);

        self::assertCount(1, $client->files($this->account, 'racine'));
        self::assertCount(3, $this->calls);
    }

    /** Au-delà, ce n'est plus une liste qu'on parcourt des yeux. */
    public function testTheDescentStopsAtTheDepthLimit(): void
    {
        $responses = [$this->token()];

        // Sept étages pour une borne à cinq.
        for ($level = 0; $level < 7; ++$level) {
            $responses[] = $this->listing([$this->folder('d-'.$level, 'N'.$level, 0 === $level ? 'racine' : 'd-'.($level - 1))]);
        }

        $client = $this->client($responses);
        $client->files($this->account, 'racine');

        // Le jeton plus cinq étages, pas sept.
        self::assertCount(6, $this->calls);
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

    /**
     * Google sert ses vignettes depuis son CDN, sans authentification :
     * mesuré à deux cent vingt pixels et moins d'un kilo-octet. Elles
     * voyagent donc telles quelles, ce qui épargne un appel par vignette.
     */
    public function testTheThumbnailTravelsWhenGoogleHasOne(): void
    {
        $client = $this->client([$this->token(), $this->listing([
            ['id' => 'a', 'name' => 'photo.jpg', 'mimeType' => 'image/jpeg', 'parents' => ['dossier-1'], 'thumbnailLink' => 'https://lh3.example/x=s220'],
            ['id' => 'b', 'name' => 'archive.zip', 'mimeType' => 'application/zip', 'parents' => ['dossier-1']],
        ])]);

        $files = $client->files($this->account, 'dossier-1');
        $thumbnails = array_combine(array_column($files, 'name'), array_column($files, 'thumbnail'));

        self::assertSame('https://lh3.example/x=s220', $thumbnails['photo.jpg']);
        // Rien pour ce dont Google ne sait pas faire d'image.
        self::assertNull($thumbnails['archive.zip']);
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
