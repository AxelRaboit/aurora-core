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

use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function str_contains;

/**
 * Ce que le client reçoit vraiment quand il clique.
 *
 * Trois choses se décident ici et ne se voient pas à l'œil : le nom que porte
 * le fichier téléchargé, qui ne peut venir que de Google ; le fait qu'un
 * aperçu ne paie pas l'appel qui sert à ce nom ; et le sort d'un HTML posé
 * dans un dossier partagé, qui s'exécuterait sous le domaine d'Aurora avec la
 * session de celui qui le regarde.
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

    private function content(string $type): MockResponse
    {
        return new MockResponse('octets', ['response_headers' => [
            'content-type' => $type,
            // Ce que Google renvoie vraiment : une pièce jointe sans nom.
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
        $server = $this->server([$this->token(), $this->content('application/pdf')]);

        $response = $server->serve($this->account, 'fichier-1');

        self::assertInstanceOf(Response::class, $response);
        self::assertFalse($response->headers->has('Content-Disposition'));
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));

        // Le jeton, puis le contenu. Rien de plus : redemander le nom pour un
        // aperçu ajouterait un aller-retour à chaque ouverture.
        self::assertCount(2, $this->urls);
    }

    public function testADownloadCarriesTheNameGoogleGives(): void
    {
        $server = $this->server([
            $this->token(),
            $this->content('application/pdf'),
            $this->metadata('Devis 2026.pdf'),
        ]);

        $response = $server->serve($this->account, 'fichier-1', download: true);

        self::assertInstanceOf(Response::class, $response);

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposition);
        self::assertStringContainsString('Devis 2026.pdf', $disposition);
    }

    /**
     * Le nom manque, le fichier non.
     *
     * Renvoyer un 404 parce que la seconde requête a échoué priverait le
     * client d'un fichier qui est là ; la pièce jointe part sans nom.
     */
    public function testADownloadSurvivesAMissingName(): void
    {
        $server = $this->server([
            $this->token(),
            $this->content('application/pdf'),
            new MockResponse('', ['http_code' => 500]),
        ]);

        $response = $server->serve($this->account, 'fichier-1', download: true);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('attachment', $response->headers->get('Content-Disposition'));
    }

    /**
     * **Un HTML dans un dossier partagé ne s'ouvre pas dans un onglet.**.
     *
     * Le fichier sort sous le domaine d'Aurora : affiché, son script tournerait
     * sur la page d'un espace avec la session de celui qui regarde. Il
     * redevient un fichier même quand personne n'a demandé à le télécharger.
     */
    public function testAnExecutableTypeIsForcedToDownloadEvenWithoutAsking(): void
    {
        $server = $this->server([
            $this->token(),
            $this->content('text/html'),
            $this->metadata('piege.html'),
        ]);

        $response = $server->serve($this->account, 'fichier-1');

        self::assertInstanceOf(Response::class, $response);
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testNothingIsCachedByAnIntermediary(): void
    {
        $server = $this->server([$this->token(), $this->content('image/png')]);

        $response = $server->serve($this->account, 'fichier-1');

        self::assertInstanceOf(Response::class, $response);
        // Symfony réordonne les directives : on vérifie ce qu'elles disent,
        // pas la façon dont elles sont écrites.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testAFileOutOfReachIsNullAndNotAnError(): void
    {
        $server = $this->server([$this->token(), new MockResponse('', ['http_code' => 404])]);

        self::assertNull($server->serve($this->account, 'disparu'));
    }

    public function testTheNameIsAskedForTheRightFile(): void
    {
        $server = $this->server([
            $this->token(),
            $this->content('application/pdf'),
            $this->metadata('Contrat.pdf'),
        ]);

        $server->serve($this->account, 'fichier-42', download: true);

        $asked = $this->urls[2] ?? '';
        self::assertStringContainsString('fichier-42', $asked);
        self::assertTrue(str_contains($asked, 'fields=name%2CmimeType') || str_contains($asked, 'fields=name,mimeType'));
    }
}
