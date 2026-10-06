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
 * What the server asks Drive for, and what it does with the answers.
 *
 * Three things go wrong silently: a folder shared from a shared Drive that
 * returns an empty list without an error, a trashed file that comes back as a
 * live one, and a failure token kept for an hour. None of them may raise an
 * exception in the middle of a client space.
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

    /** A token lasts an hour: asking for it again on every page is a wasted round trip. */
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

    /** The trash stays in the folder and would come back as a file. */
    public function testTheQueryExcludesTheTrash(): void
    {
        $client = $this->client([$this->token(), $this->listing([])]);

        $client->files($this->account, 'dossier-1');

        $query = $this->calls[1]['options']['query'];

        self::assertStringContainsString("'dossier-1' in parents", $query['q']);
        self::assertStringContainsString('trashed = false', $query['q']);
        // Folders are no longer excluded: they are how the walk goes down.
        self::assertStringNotContainsString('mimeType !=', $query['q']);
    }

    /**
     * **One call per level, not per folder.** Going down folder by folder
     * would have made one request each; this test is the only thing that will
     * stop someone from rewriting the loop the obvious way.
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

        // The token, the root, then both folders together: three calls, not
        // four.
        self::assertCount(3, $this->calls);
        self::assertStringContainsString("'d-a' in parents or 'd-b' in parents", $this->calls[2]['options']['query']['q']);
        self::assertCount(2, $files);
    }

    /** A flat list that does not say where each file comes from would be unreadable. */
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

    /** A circular shortcut would make the walk go round forever. */
    public function testAFolderThatPointsBackAtItselfDoesNotLoop(): void
    {
        $client = $this->client([
            $this->token(),
            $this->listing([$this->folder('d-a', 'Boucle', 'racine')]),
            // The same folder, returned by itself.
            $this->listing([$this->folder('d-a', 'Boucle', 'd-a'), $this->file('f-1', 'seul.pdf', 'd-a')]),
        ]);

        self::assertCount(1, $client->files($this->account, 'racine'));
        self::assertCount(3, $this->calls);
    }

    /** Beyond that, it is no longer a list one scans by eye. */
    public function testTheDescentStopsAtTheDepthLimit(): void
    {
        $responses = [$this->token()];

        // Seven levels for a limit of five.
        for ($level = 0; $level < 7; ++$level) {
            $responses[] = $this->listing([$this->folder('d-'.$level, 'N'.$level, 0 === $level ? 'racine' : 'd-'.($level - 1))]);
        }

        $client = $this->client($responses);
        $client->files($this->account, 'racine');

        // The token plus five levels, not seven.
        self::assertCount(6, $this->calls);
    }

    /**
     * Without these two flags, a folder shared from a shared Drive returns an
     * empty list - and returns an empty list without saying why.
     */
    public function testSharedDrivesAreIncluded(): void
    {
        $client = $this->client([$this->token(), $this->listing([])]);

        $client->files($this->account, 'dossier-1');

        $query = $this->calls[1]['options']['query'];

        self::assertSame('true', $query['supportsAllDrives']);
        self::assertSame('true', $query['includeItemsFromAllDrives']);
    }

    /** A shared folder is read starting with what just arrived in it. */
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
     * Google serves its thumbnails from its CDN, without authentication:
     * measured at two hundred and twenty pixels and under a kilobyte. They
     * therefore travel as they are, which saves one call per thumbnail.
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
        // Nothing for what Google cannot make an image of.
        self::assertNull($thumbnails['archive.zip']);
    }

    /** A Google document has no bytes until it is exported. */
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

    /** A key that was just corrected must work right away. */
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

    /** A file removed from the share is not an error, it is a message. */
    public function testARefusedDownloadIsNull(): void
    {
        $client = $this->client([$this->token(), new MockResponse('', ['http_code' => 404])]);

        self::assertNull($client->download($this->account, 'fichier-parti'));
    }

    /**
     * The stream is returned as is: putting a fifty-megabyte video in a PHP
     * string to spit it back out would bring the server down.
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
