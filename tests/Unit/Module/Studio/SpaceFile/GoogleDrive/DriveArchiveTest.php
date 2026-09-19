<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\SpaceFile\GoogleDrive;

use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveArchive;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveClient;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use ZipArchive;

use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function unlink;

/**
 * Le lot, et ce qu'il fait des cas que Drive autorise et pas un zip.
 *
 * Deux fichiers du même nom dans un dossier sont légaux chez Drive ; extraits
 * d'une archive, l'un écraserait l'autre. Et un document Google n'a pas
 * d'octets à télécharger : il ne peut pas entrer, et se taire ferait croire à
 * un lot complet.
 */
final class DriveArchiveTest extends TestCase
{
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
    private function archive(array $responses): DriveArchive
    {
        $http = new MockHttpClient(function () use (&$responses): ResponseInterface {
            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new DriveArchive(new DriveClient($http, new ArrayAdapter(), new NullLogger()));
    }

    private function token(): MockResponse
    {
        return new MockResponse((string) json_encode(['access_token' => 'jeton-google', 'expires_in' => 3600]), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /** @return array{id: string, name: string, path: string, mimeType: string, size: int|null, modifiedAt: string|null, thumbnail: string|null} */
    private function file(string $id, string $name, string $path = '', ?int $size = 10): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'path' => $path,
            'mimeType' => 'application/pdf',
            'size' => $size,
            'modifiedAt' => null,
            'thumbnail' => null,
        ];
    }

    /** @return list<string> */
    private function entriesOf(string $path): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));

        $names = [];

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $names[] = (string) $zip->getNameIndex($index);
        }

        $zip->close();

        return $names;
    }

    public function testTheFolderTreeIsKeptInsideTheArchive(): void
    {
        $archive = $this->archive([
            $this->token(),
            new MockResponse('a'),
            new MockResponse('b'),
        ]);

        $path = $archive->zipFor($this->account, [
            $this->file('1', 'Brief.pdf'),
            $this->file('2', 'Contrat.pdf', 'Contrats/2026'),
        ]);

        self::assertSame(['Brief.pdf', 'Contrats/2026/Contrat.pdf'], $this->entriesOf($path));

        unlink($path);
    }

    public function testTwoFilesOfTheSameNameBothSurvive(): void
    {
        $archive = $this->archive([
            $this->token(),
            new MockResponse('a'),
            new MockResponse('b'),
        ]);

        $path = $archive->zipFor($this->account, [
            $this->file('1', 'Photo.jpg'),
            $this->file('2', 'Photo.jpg'),
        ]);

        self::assertSame(['Photo.jpg', 'Photo.jpg (2)'], $this->entriesOf($path));

        unlink($path);
    }

    public function testWhatCouldNotBeTakenIsNamedRatherThanDropped(): void
    {
        $archive = $this->archive([
            $this->token(),
            new MockResponse('a'),
            // Un document Google : Google refuse de servir des octets.
            new MockResponse('', ['http_code' => 403]),
        ]);

        $path = $archive->zipFor($this->account, [
            $this->file('1', 'Brief.pdf'),
            $this->file('2', 'Compte rendu', '', null),
        ]);

        $entries = $this->entriesOf($path);

        self::assertContains('Brief.pdf', $entries);
        self::assertContains('FICHIERS-NON-INCLUS.txt', $entries);

        unlink($path);
    }

    /** Une archive sans entrée est refusée par certains outils. */
    public function testAnEmptyFolderStillProducesAReadableArchive(): void
    {
        $archive = $this->archive([]);

        $path = $archive->zipFor($this->account, []);

        self::assertSame(['LISEZ-MOI.txt'], $this->entriesOf($path));

        unlink($path);
    }

    public function testAnEntryCannotClimbOutOfTheFolderItIsExtractedInto(): void
    {
        $archive = $this->archive([$this->token(), new MockResponse('a')]);

        $path = $archive->zipFor($this->account, [
            $this->file('1', '../../etc/passwd'),
        ]);

        foreach ($this->entriesOf($path) as $entry) {
            self::assertStringNotContainsString('..', $entry);
        }

        unlink($path);
    }

    /** Les documents Google n'ont pas de taille, et ne pèsent donc rien. */
    public function testTheAnnouncedWeightIgnoresWhatHasNoBytes(): void
    {
        $archive = $this->archive([]);

        self::assertSame(30, $archive->weightOf([
            $this->file('1', 'a.pdf', '', 10),
            $this->file('2', 'b.pdf', '', 20),
            $this->file('3', 'Compte rendu', '', null),
        ]));
    }
}
