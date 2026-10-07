<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\SpaceFile\GoogleDrive;

use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveClient;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveFileServer;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function array_map;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function str_contains;

/**
 * What the client really receives when they click.
 *
 * Three things are decided here and cannot be seen by eye: the name the
 * downloaded file carries, which can only come from Google; the fact that a
 * preview does not pay for the call that fetches that name; and the fate of an
 * HTML file dropped in a shared folder, which would run under Aurora's domain
 * with the session of whoever looks at it.
 */
final class DriveFileServerTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

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
    private function server(array $responses): DriveFileServer
    {
        $this->urls = [];

        $http = new MockHttpClient(function (string $method, string $url) use (&$responses): ResponseInterface {
            $this->urls[] = $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new DriveFileServer(new DriveClient($http, new ArrayAdapter(), new NullLogger()));
    }

    private function token(): MockResponse
    {
        return new MockResponse((string) json_encode(['access_token' => 'jeton-google', 'expires_in' => 3600]), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /** The space's folder, as Google lists it: these files and no others. */
    private function listing(string ...$ids): MockResponse
    {
        $ids = [] === $ids ? ['fichier-1', 'fichier-42', 'disparu'] : $ids;
        $files = array_map(static fn (string $id): array => ['id' => $id, 'name' => $id.'.pdf', 'mimeType' => 'application/pdf', 'parents' => ['dossier']], $ids);

        return new MockResponse((string) json_encode(['files' => $files]), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function content(string $type): MockResponse
    {
        return new MockResponse('octets', ['response_headers' => [
            'content-type' => $type,
            // What Google really sends back: an attachment without a name.
            'content-disposition' => 'attachment',
            'content-length' => '6',
        ]]);
    }

    private function metadata(string $name): MockResponse
    {
        return new MockResponse((string) json_encode(['name' => $name, 'mimeType' => 'application/pdf']), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    public function testAPreviewCostsNoExtraCallAndCarriesNoDisposition(): void
    {
        $server = $this->server([$this->token(), $this->listing(), $this->content('application/pdf')]);

        $response = $server->serve($this->account, 'dossier', 'fichier-1');

        self::assertInstanceOf(Response::class, $response);
        self::assertFalse($response->headers->has('Content-Disposition'));
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));

        // The token, the folder listing, then the content. Nothing more:
        // asking for the name again for a preview would add a round trip to
        // every opening.
        self::assertCount(3, $this->urls);
    }

    public function testADownloadCarriesTheNameGoogleGives(): void
    {
        $server = $this->server([
            $this->token(),
            $this->listing(),
            $this->content('application/pdf'),
            $this->metadata('Devis 2026.pdf'),
        ]);

        $response = $server->serve($this->account, 'dossier', 'fichier-1', download: true);

        self::assertInstanceOf(Response::class, $response);

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposition);
        self::assertStringContainsString('Devis 2026.pdf', $disposition);
    }

    /**
     * The name is missing, the file is not.
     *
     * Returning a 404 because the second request failed would deprive the
     * client of a file that is there; the attachment goes out without a name.
     */
    public function testADownloadSurvivesAMissingName(): void
    {
        $server = $this->server([
            $this->token(),
            $this->listing(),
            $this->content('application/pdf'),
            new MockResponse('', ['http_code' => 500]),
        ]);

        $response = $server->serve($this->account, 'dossier', 'fichier-1', download: true);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('attachment', $response->headers->get('Content-Disposition'));
    }

    /**
     * **An HTML file in a shared folder does not open in a tab.**.
     *
     * The file goes out under Aurora's domain: displayed, its script would run
     * on a space's page with the session of whoever is looking. It becomes a
     * file again even when nobody asked to download it.
     */
    public function testAnExecutableTypeIsForcedToDownloadEvenWithoutAsking(): void
    {
        $server = $this->server([
            $this->token(),
            $this->listing(),
            $this->content('text/html'),
            $this->metadata('piege.html'),
        ]);

        $response = $server->serve($this->account, 'dossier', 'fichier-1');

        self::assertInstanceOf(Response::class, $response);
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testNothingIsCachedByAnIntermediary(): void
    {
        $server = $this->server([$this->token(), $this->listing(), $this->content('image/png')]);

        $response = $server->serve($this->account, 'dossier', 'fichier-1');

        self::assertInstanceOf(Response::class, $response);
        // Symfony reorders the directives: what they say is checked, not the
        // way they are written.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testAFileOutOfReachIsNullAndNotAnError(): void
    {
        $server = $this->server([$this->token(), $this->listing(), new MockResponse('', ['http_code' => 404])]);

        self::assertNull($server->serve($this->account, 'dossier', 'disparu'));
    }

    public function testTheNameIsAskedForTheRightFile(): void
    {
        $server = $this->server([
            $this->token(),
            $this->listing(),
            $this->content('application/pdf'),
            $this->metadata('Contrat.pdf'),
        ]);

        $server->serve($this->account, 'dossier', 'fichier-42', download: true);

        $asked = $this->urls[3] ?? '';
        self::assertStringContainsString('fichier-42', $asked);
        self::assertTrue(str_contains($asked, 'fields=name%2CmimeType') || str_contains($asked, 'fields=name,mimeType'));
    }

    /**
     * **The service account reads other folders than the space's one.**
     * An id guessed or copied from another client's space serves nothing, and
     * Google is not even asked for the content.
     */
    public function testAFileOutsideTheSpacesFolderIsNotServed(): void
    {
        $server = $this->server([$this->token(), $this->listing('fichier-1'), $this->content('application/pdf')]);

        self::assertNull($server->serve($this->account, 'dossier', 'fichier-d-un-autre-client'));
        self::assertCount(2, $this->urls, 'the token and the listing, never the content');
    }
}
