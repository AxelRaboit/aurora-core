<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Pexels\Command;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Core\Storage\ActiveStorageDiskProviderInterface;
use Aurora\Core\Storage\Adapter\LocalStorageAdapter;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Service\ImageCropper;
use Aurora\Core\Storage\Service\PdfThumbnailGenerator;
use Aurora\Core\Storage\Service\VideoPosterGenerator;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Dto\DocumentInputFactory;
use Aurora\Module\Ged\Document\Dto\DocumentInputInterface;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Document\Service\GedDocumentUploader;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\DocumentCategory\Service\DocumentCategoryResolver;
use Aurora\Module\Ged\DocumentCategory\Service\InlineUploadCategoryProvider;
use Aurora\Module\Ged\Pexels\Command\ImportPexelsPhotosCommand;
use Aurora\Module\Ged\Pexels\Service\PexelsClient;
use Aurora\Module\Ged\Pexels\Service\PexelsImporter;
use Aurora\Module\Ged\Pexels\Setting\PexelsSettingEnum;
use Aurora\Module\Ged\Pexels\Setting\PexelsSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The console door onto the Pexels module.
 *
 * What is worth proving here is not the download - {@see PexelsImporterTest}
 * owns that - but the decisions the command makes around it: that a switched
 * off integration is reported rather than half-attempted, that one bad id does
 * not take the rest of the run down with it while still failing the exit code,
 * and that `--dry-run` really does stop before spending anything.
 *
 * The collaborators are real. Both are `final readonly` and cannot be doubled,
 * and doubling them would be testing the doubles anyway: an HTTP client that
 * fails the test when called is a sharper way of saying "nothing was fetched".
 */
final class ImportPexelsPhotosCommandTest extends TestCase
{
    /** A 1x1 PNG - the smallest thing GD will agree is an image. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $workDir;

    /** @var list<array<string, mixed>> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/aurora-pexels-cmd-'.uniqid();
        (new Filesystem())->mkdir($this->workDir);
        $this->created = [];
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
    }

    public function testASwitchedOffIntegrationIsReportedAndNothingIsAttempted(): void
    {
        $tester = $this->tester(configured: false);

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['2014422']]));
        self::assertStringContainsString('not configured', $tester->getDisplay());
        self::assertSame([], $this->created);
    }

    /**
     * A run of ids is a list somebody typed, and a typo in the middle of it
     * should cost that one photo, not the four that follow.
     */
    public function testOneUnknownIdDoesNotStopTheOthersButFailsTheRun(): void
    {
        $tester = $this->tester(lookups: [
            '404404' => new MockResponse('{"error":"Not Found"}', ['http_code' => 404]),
            '2014422' => new MockResponse($this->photoPayload(), ['http_code' => 200]),
        ]);

        $status = $tester->execute(['ids' => ['404404', '2014422']]);

        self::assertCount(1, $this->created, 'the good id is still imported');
        self::assertStringContainsString('404404', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $status, 'the run reports that something was missed');
    }

    /**
     * Provenance is the part a hand-rolled bypass forgets, and the part
     * Pexels asks for: the credit under the picture is rendered from these
     * three columns.
     */
    public function testTheImportCarriesTheProvenanceTheCreditIsRenderedFrom(): void
    {
        $tester = $this->tester(lookups: [
            '2014422' => new MockResponse($this->photoPayload(), ['http_code' => 200]),
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['2014422']]));
        self::assertCount(1, $this->created);
        self::assertSame(
            'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg',
            $this->created[0]['sourceUrl'],
        );
        self::assertSame('Jane Doe', $this->created[0]['attributionName']);
        self::assertSame('https://www.pexels.com/@jane', $this->created[0]['attributionUrl']);
        self::assertSame('A tidy desk', $this->created[0]['alt']);

        // And filed where the picker files them. This is the one that was
        // missed when the import was rebuilt by hand around the module: the
        // credit still rendered, so nothing looked wrong, and the photos were
        // simply nowhere in the médiathèque's own filing.
        self::assertSame(1, $this->created[0]['categoryId']);
    }

    public function testADryRunLooksUpAndStopsBeforeDownloading(): void
    {
        $tester = $this->tester(lookups: [
            '2014422' => new MockResponse($this->photoPayload(), ['http_code' => 200]),
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['2014422'], '--dry-run' => true]));
        self::assertStringContainsString('A tidy desk', $tester->getDisplay());
        self::assertSame([], $this->created, 'nothing is filed by a dry run');
    }

    // --- harness -----------------------------------------------------------

    /**
     * @param array<string, MockResponse> $lookups keyed by the id the command asks for
     */
    private function tester(bool $configured = true, array $lookups = []): CommandTester
    {
        $client = new PexelsClient(
            new MockHttpClient(function (string $method, string $url) use ($lookups): MockResponse {
                foreach ($lookups as $id => $response) {
                    if (str_ends_with($url, '/photos/'.$id)) {
                        return $response;
                    }
                }

                self::fail(sprintf('Unexpected request: %s %s', $method, $url));
            }),
            new NullLogger(),
            $this->settings($configured),
        );

        return new CommandTester(new ImportPexelsPhotosCommand($client, $this->importer()));
    }

    /**
     * The real importer, writing a real file into a temporary directory. Its
     * download client answers the one PNG; a command that should not have
     * downloaded anything simply never asks it.
     */
    private function importer(): PexelsImporter
    {
        $category = new DocumentCategory();
        $category->setName('Médias éditoriaux');
        new ReflectionProperty($category, 'id')->setValue($category, 1);

        $categoryRepository = $this->createStub(DocumentCategoryRepository::class);
        $categoryRepository->method('findOneBy')->willReturn($category);

        // Provider and resolver are both `final readonly`, so each is built
        // without its constructor and given only what the read path touches -
        // the same trick PexelsImporterTest uses, and for the same reason.
        $resolver = new ReflectionClass(DocumentCategoryResolver::class)->newInstanceWithoutConstructor();
        new ReflectionProperty($resolver, 'documentCategoryRepository')->setValue($resolver, $categoryRepository);

        $provider = new ReflectionClass(InlineUploadCategoryProvider::class)->newInstanceWithoutConstructor();
        new ReflectionProperty($provider, 'documentCategoryResolver')->setValue($provider, $resolver);

        $manager = $this->createStub(DocumentManagerInterface::class);
        $manager->method('create')->willReturnCallback(function (DocumentInputInterface $input): DocumentInterface {
            $this->created[] = [
                'title' => $input->getTitle(),
                'sourceUrl' => $input->getSourceUrl(),
                'attributionName' => $input->getAttributionName(),
                'attributionUrl' => $input->getAttributionUrl(),
                'alt' => $input->getAlt(),
                'categoryId' => $input->getCategoryId(),
            ];

            // The real manager hands back a persisted document; a bare one
            // would leave `title` uninitialised and the command's own summary
            // line would be what failed, not the import.
            $document = new Document();
            $document->setTitle($input->getTitle())->setAttributionName($input->getAttributionName());

            return $document;
        });

        return new PexelsImporter(
            $manager,
            new DocumentInputFactory(),
            $provider,
            $this->uploader(),
            new MockHttpClient(new MockResponse((string) base64_decode(self::PNG, strict: true))),
        );
    }

    /**
     * Built exactly as {@see PexelsImporterTest} builds it: a real uploader
     * over a temporary directory, so the download really does become a stored
     * file with dimensions rather than a mock agreeing that it did.
     */
    private function uploader(): GedDocumentUploader
    {
        $filesystem = new Filesystem();
        $workspace = new LocalWorkspace($filesystem);

        return new GedDocumentUploader(
            new AsciiSlugger(),
            new PdfThumbnailGenerator($workspace),
            new VideoPosterGenerator($workspace),
            new ImageCropper($filesystem),
            new StorageManager(
                [new LocalStorageAdapter($filesystem, $this->workDir)],
                new class implements ActiveStorageDiskProviderInterface {
                    public function activeDisk(): StorageDiskEnum
                    {
                        return StorageDiskEnum::Local;
                    }
                },
            ),
            $workspace,
        );
    }

    private function settings(bool $configured): PexelsSettings
    {
        $store = $configured ? [
            PexelsSettingEnum::Enabled->value => '1',
            PexelsSettingEnum::ApiKey->value => base64_encode('key'),
            PexelsSettingEnum::TermsAcceptedAt->value => '2026-09-06T12:00:00+00:00',
        ] : [];

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

        return new PexelsSettings($repository, $encryption);
    }

    private function photoPayload(): string
    {
        return json_encode([
            'id' => 2014422,
            'width' => 4000,
            'height' => 3000,
            'alt' => 'A tidy desk',
            'photographer' => 'Jane Doe',
            'photographer_url' => 'https://www.pexels.com/@jane',
            'src' => ['original' => 'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg'],
        ], JSON_THROW_ON_ERROR);
    }
}
