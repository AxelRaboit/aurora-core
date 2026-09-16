<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Pexels\Service;

use Aurora\Core\Storage\ActiveStorageDiskProviderInterface;
use Aurora\Core\Storage\Adapter\LocalStorageAdapter;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Service\ImageCropper;
use Aurora\Core\Storage\Service\PdfThumbnailGenerator;
use Aurora\Core\Storage\Service\VideoPosterGenerator;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
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
use Aurora\Module\Ged\Pexels\Service\PexelsImporter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The import is where a URL chosen in a browser becomes a file on our disk
 * and a row every page on the site will render. Most of what matters here is
 * a refusal; the rest is that the picture really does land locally.
 *
 * The uploader is the real one, writing into a temporary directory, because
 * doubling it would test the mock: whether the download becomes a stored file
 * with dimensions is the question.
 */
final class PexelsImporterTest extends TestCase
{
    /** A 1x1 PNG - the smallest thing GD will agree is an image. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var array<string, mixed>|null */
    private ?array $captured = null;

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/aurora-pexels-'.uniqid();
        (new Filesystem())->mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
    }

    /** @param array<string, mixed> $photo */
    private function import(array $photo, ?MockHttpClient $http = null): DocumentInterface
    {
        $category = new DocumentCategory();
        $category->setName('Inline');
        // The id is normally the database's to give; the importer only reads it.
        $id = new ReflectionProperty($category, 'id');
        $id->setValue($category, 1);

        // Both the provider and the resolver behind it are `final readonly`,
        // so neither can be doubled. Each is built without its constructor and
        // given only what the read path touches: the resolver finds the
        // category through the repository and never reaches the two
        // collaborators that would have needed a database, and the provider
        // only ever calls the resolver.
        $categoryRepository = $this->createStub(DocumentCategoryRepository::class);
        $categoryRepository->method('findOneBy')->willReturn($category);

        $categoryResolver = (new ReflectionClass(DocumentCategoryResolver::class))->newInstanceWithoutConstructor();
        new ReflectionProperty($categoryResolver, 'documentCategoryRepository')->setValue($categoryResolver, $categoryRepository);

        $categoryProvider = (new ReflectionClass(InlineUploadCategoryProvider::class))->newInstanceWithoutConstructor();
        new ReflectionProperty($categoryProvider, 'documentCategoryResolver')->setValue($categoryProvider, $categoryResolver);

        $manager = $this->createStub(DocumentManagerInterface::class);
        $manager->method('create')->willReturnCallback(function (DocumentInputInterface $input): DocumentInterface {
            $this->captured = [
                'title' => $input->getTitle(),
                'filePath' => $input->getFilePath(),
                'mimeType' => $input->getMimeType(),
                'width' => $input->getWidth(),
                'sourceUrl' => $input->getSourceUrl(),
                'attributionName' => $input->getAttributionName(),
                'attributionUrl' => $input->getAttributionUrl(),
                'alt' => $input->getAlt(),
            ];

            return new Document();
        });

        $importer = new PexelsImporter(
            $manager,
            new DocumentInputFactory(),
            $categoryProvider,
            $this->makeUploader(),
            $http ?? $this->respondingWith((string) base64_decode(self::PNG, strict: true)),
        );

        return $importer->import($photo);
    }

    private function respondingWith(string $body, int $status = 200): MockHttpClient
    {
        return new MockHttpClient(new MockResponse($body, ['http_code' => $status]));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function photo(array $overrides = []): array
    {
        return [
            'url' => 'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg',
            'authorName' => 'Jane Doe',
            'authorUrl' => 'https://www.pexels.com/@jane',
            'description' => 'A tidy desk',
            'width' => 4000,
            'height' => 3000,
            ...$overrides,
        ];
    }

    /**
     * The URL arrives from the browser, and a browser can be told to send
     * anything. Without the host check, "import" would fetch whatever address
     * it was handed - a request forgery with a document library attached.
     */
    public function testAnAddressOutsidePexelsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->import(
            $this->photo(['url' => 'http://169.254.169.254/latest/meta-data/']),
            new MockHttpClient(static function (): MockResponse {
                self::fail('Nothing outside the allowed host may be fetched.');
            }),
        );
    }

    /** A lookalike host is the whole reason the check reads the host and not the string. */
    public function testALookalikeHostIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->import($this->photo(['url' => 'https://images.pexels.com.evil.example.com/photo.jpeg']));
    }

    /** Attribution is a condition of use, so a photo without one cannot be filed. */
    public function testAPhotoWithoutAPhotographerIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->import($this->photo(['authorName' => '  ']));
    }

    /**
     * The point of the whole change: the bytes are ours afterwards. A stored
     * path, real dimensions read from the file, and no reason left to ask
     * anyone else for the picture.
     */
    public function testThePhotoIsDownloadedAndStored(): void
    {
        $this->import($this->photo());

        self::assertNotNull($this->captured['filePath']);
        self::assertFileExists($this->workDir.'/'.$this->captured['filePath']);
        self::assertSame('image/png', $this->captured['mimeType']);
        self::assertSame(1, $this->captured['width']);
    }

    /** Provenance outlives the download: it is what the credit is rendered from. */
    public function testTheProvenanceIsKeptAlongsideTheFile(): void
    {
        $this->import($this->photo());

        self::assertSame(
            'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg',
            $this->captured['sourceUrl'],
        );
        self::assertSame('Jane Doe', $this->captured['attributionName']);
        self::assertSame('https://www.pexels.com/@jane', $this->captured['attributionUrl']);
        self::assertSame('A tidy desk', $this->captured['title']);
    }

    /** A photo Pexels left undescribed still needs a name in the library. */
    public function testAnUndescribedPhotoIsTitledAfterItsAuthor(): void
    {
        $this->import($this->photo(['description' => null]));

        self::assertSame('Pexels - Jane Doe', $this->captured['title']);
    }

    /**
     * Told apart from a refused payload on purpose: the photo is fine, the
     * fetch is not, and the answer for the editor is to try again rather than
     * to pick another picture.
     */
    public function testAFailedFetchRaisesARuntimeErrorRatherThanFilingNothing(): void
    {
        $this->expectException(RuntimeException::class);

        $this->import($this->photo(), $this->respondingWith('', 503));
    }

    /**
     * The host is allowed, so the guard above says nothing - but what came
     * back is not a picture, and a picture field is no place for it.
     */
    public function testAFetchThatIsNotAnImageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->import($this->photo(), $this->respondingWith('<!doctype html><title>nope</title>'));
    }

    private function makeUploader(): GedDocumentUploader
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
}
