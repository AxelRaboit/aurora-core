<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Document\Manager;

use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Core\Storage\Service\ImageCropper;
use Aurora\Core\Storage\Service\ImageVariantGenerator;
use Aurora\Core\Storage\Service\PdfThumbnailGenerator;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\Document\Dto\DocumentInputInterface;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Entity\DocumentVersion;
use Aurora\Module\Ged\Document\Entity\DocumentVersionInterface;
use Aurora\Module\Ged\Document\Manager\DocumentManager;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;
use Aurora\Module\Ged\Document\Service\GedDocumentUploader;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTagInterface;
use Aurora\Module\Ged\DocumentTag\Repository\DocumentTagRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

use function dirname;

#[AllowMockObjectsWithoutExpectations]
final class DocumentManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private DocumentCategoryRepository $categoryRepository;
    private DocumentTagRepository $tagRepository;
    private DocumentFolderRepository $folderRepository;
    private DocumentVersionRepository $versionRepository;
    private DocumentRepository $documentRepository;
    private DocumentManager $manager;
    private string $workDir;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->categoryRepository = $this->createMock(DocumentCategoryRepository::class);
        $this->tagRepository = $this->createMock(DocumentTagRepository::class);
        $this->folderRepository = $this->createMock(DocumentFolderRepository::class);
        $this->versionRepository = $this->createMock(DocumentVersionRepository::class);
        $this->versionRepository->method('getNextVersionNumber')->willReturn(1);
        $this->documentRepository = $this->createMock(DocumentRepository::class);

        $settingRepository = $this->createStub(SettingRepository::class);
        $settingRepository->method('getOrDefault')->willReturn(SequencePrefixEnum::GedDocument->value);

        $this->workDir = sys_get_temp_dir().'/aurora-ged-manager-'.uniqid();
        mkdir($this->workDir, 0o777, true);

        $this->manager = new DocumentManager(
            $this->entityManager,
            $this->categoryRepository,
            $this->makeSequenceGenerator(),
            $settingRepository,
            $this->createStub(AuditLogger::class),
            $this->tagRepository,
            $this->folderRepository,
            $this->versionRepository,
            $this->documentRepository,
            new GedDocumentUploader(
                new Filesystem(),
                new AsciiSlugger(),
                new PdfThumbnailGenerator($this->workDir),
                new ImageCropper(new Filesystem()),
                $this->workDir,
            ),
            new ImageVariantGenerator(new Filesystem(), $this->workDir),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
    }

    private function makeSequenceGenerator(): SequenceGenerator
    {
        $dbalResult = $this->createStub(Result::class);
        $dbalResult->method('fetchOne')->willReturn(1);

        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($dbalResult);

        return new SequenceGenerator($connection);
    }

    private function makeInput(
        string $title = 'Test Document',
        ?string $description = null,
        DocumentStatusEnum $status = DocumentStatusEnum::Draft,
        ?int $categoryId = null,
        ?string $filePath = null,
        ?string $fileName = null,
        ?string $originalName = null,
        ?string $mimeType = null,
        ?int $size = null,
        array $tagIds = [],
        ?int $folderId = null,
    ): DocumentInputInterface {
        $input = $this->createStub(DocumentInputInterface::class);
        $input->method('getTitle')->willReturn($title);
        $input->method('getDescription')->willReturn($description);
        $input->method('getStatus')->willReturn($status);
        $input->method('getCategoryId')->willReturn($categoryId);
        $input->method('getFilePath')->willReturn($filePath);
        $input->method('getFileName')->willReturn($fileName);
        $input->method('getOriginalName')->willReturn($originalName);
        $input->method('getMimeType')->willReturn($mimeType);
        $input->method('getSize')->willReturn($size);
        $input->method('getTagIds')->willReturn($tagIds);
        $input->method('getFolderId')->willReturn($folderId);

        return $input;
    }

    private function captureDocument(mixed &$captured): void
    {
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$captured): void {
                if ($entity instanceof Document) {
                    $captured = $entity;
                }
            }
        );
    }

    private function captureDocumentVersion(mixed &$captured): void
    {
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$captured): void {
                if ($entity instanceof DocumentVersion) {
                    $captured = $entity;
                }
            }
        );
    }

    // --- create() ---

    public function testCreateSetsReferenceOnDocument(): void
    {
        $captured = null;
        $this->captureDocument($captured);

        $this->manager->create($this->makeInput());

        self::assertInstanceOf(Document::class, $captured);
        self::assertNotNull($captured->getReference());
    }

    public function testCreateAppliesTitleAndStatus(): void
    {
        $captured = null;
        $this->captureDocument($captured);

        $this->manager->create($this->makeInput('Annual Report', 'Year 2025', DocumentStatusEnum::Published));

        self::assertSame('Annual Report', $captured->getTitle());
        self::assertSame('Year 2025', $captured->getDescription());
        self::assertSame(DocumentStatusEnum::Published, $captured->getStatus());
    }

    public function testCreateCallsPersistAndFlush(): void
    {
        $this->entityManager->expects(self::atLeastOnce())->method('persist');
        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $this->manager->create($this->makeInput());
    }

    public function testCreateReturnsDocumentInstance(): void
    {
        $result = $this->manager->create($this->makeInput('Contract'));

        self::assertInstanceOf(DocumentInterface::class, $result);
        self::assertSame('Contract', $result->getTitle());
    }

    public function testCreateRecordsVersionWhenFileIsAttached(): void
    {
        $capturedVersion = null;
        $this->captureDocumentVersion($capturedVersion);

        $this->manager->create($this->makeInput(
            filePath: 'ged/2026/05/abc.pdf',
            fileName: 'abc.pdf',
            originalName: 'Original.pdf',
            mimeType: 'application/pdf',
            size: 1234,
        ));

        self::assertInstanceOf(DocumentVersion::class, $capturedVersion);
    }

    public function testCreateDoesNotRecordVersionWithoutFile(): void
    {
        $capturedVersion = null;
        $this->captureDocumentVersion($capturedVersion);

        $this->manager->create($this->makeInput(filePath: null));

        self::assertNull($capturedVersion);
    }

    public function testCreateResolvesCategory(): void
    {
        $category = $this->createStub(DocumentCategoryInterface::class);
        $this->categoryRepository->method('find')->willReturn($category);

        $captured = null;
        $this->captureDocument($captured);

        $this->manager->create($this->makeInput(categoryId: 3));

        self::assertSame($category, $captured->getCategory());
    }

    public function testCreateWithNullCategoryIdSetsNullCategory(): void
    {
        $captured = null;
        $this->captureDocument($captured);

        $this->manager->create($this->makeInput(categoryId: null));

        self::assertNull($captured->getCategory());
    }

    public function testCreateResolvesAndAttachesTags(): void
    {
        $tag1 = $this->createStub(DocumentTagInterface::class);
        $tag2 = $this->createStub(DocumentTagInterface::class);

        $this->tagRepository->method('find')->willReturnCallback(
            static fn (int $id): ?DocumentTagInterface => match ($id) {
                1 => $tag1,
                2 => $tag2,
                default => null,
            }
        );

        $captured = null;
        $this->captureDocument($captured);

        $this->manager->create($this->makeInput(tagIds: [1, 2]));

        self::assertCount(2, $captured->getTags());
    }

    public function testCreateResolvesFolder(): void
    {
        $folder = $this->createStub(DocumentFolderInterface::class);
        $this->folderRepository->method('find')->willReturn($folder);

        $captured = null;
        $this->captureDocument($captured);

        $this->manager->create($this->makeInput(folderId: 7));

        self::assertSame($folder, $captured->getFolder());
    }

    // --- update() ---

    public function testUpdateAppliesNewTitle(): void
    {
        $document = new Document();
        $document->setTitle('Old')->setStatus(DocumentStatusEnum::Draft);

        $this->manager->update($document, $this->makeInput('New', status: DocumentStatusEnum::Published));

        self::assertSame('New', $document->getTitle());
        self::assertSame(DocumentStatusEnum::Published, $document->getStatus());
    }

    public function testUpdateCallsFlush(): void
    {
        $document = new Document();
        $document->setTitle('X')->setStatus(DocumentStatusEnum::Draft);

        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $this->manager->update($document, $this->makeInput());
    }

    public function testUpdateRecordsVersionWhenFileChanges(): void
    {
        $document = new Document();
        $document->setTitle('Doc')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/old.pdf')
            ->setFileName('old.pdf')
            ->setOriginalName('Old.pdf')
            ->setMimeType('application/pdf')
            ->setSize(100);

        $capturedVersion = null;
        $this->captureDocumentVersion($capturedVersion);

        $this->manager->update($document, $this->makeInput(
            filePath: 'ged/new.pdf',
            fileName: 'new.pdf',
            originalName: 'New.pdf',
            mimeType: 'application/pdf',
            size: 200,
        ));

        self::assertInstanceOf(DocumentVersion::class, $capturedVersion);
    }

    public function testUpdateDoesNotRecordVersionWhenFileUnchanged(): void
    {
        $document = new Document();
        $document->setTitle('Doc')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/same.pdf')
            ->setFileName('same.pdf');

        $capturedVersion = null;
        $this->captureDocumentVersion($capturedVersion);

        $this->manager->update($document, $this->makeInput(filePath: 'ged/same.pdf'));

        self::assertNull($capturedVersion);
    }

    public function testUpdateDoesNotRecordVersionWhenNewFileIdIsNull(): void
    {
        $document = new Document();
        $document->setTitle('Doc')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/keep.pdf');

        $capturedVersion = null;
        $this->captureDocumentVersion($capturedVersion);

        $this->manager->update($document, $this->makeInput(filePath: null));

        self::assertNull($capturedVersion);
    }

    public function testUpdateClearsPreviousTagsAndAppliesNew(): void
    {
        $oldTag = $this->createStub(DocumentTagInterface::class);
        $newTag = $this->createStub(DocumentTagInterface::class);

        $this->tagRepository->method('find')->willReturn($newTag);

        $document = new Document();
        $document->setTitle('Doc')->setStatus(DocumentStatusEnum::Draft)->addTag($oldTag);

        $this->manager->update($document, $this->makeInput(tagIds: [99]));

        self::assertCount(1, $document->getTags());
        self::assertTrue($document->getTags()->contains($newTag));
    }

    // --- delete() ---

    public function testDeleteCallsRemoveAndFlush(): void
    {
        $document = new Document();
        $document->setTitle('Doc')->setStatus(DocumentStatusEnum::Draft);

        $this->entityManager->expects(self::once())->method('remove')->with($document);
        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $this->manager->delete($document);
    }

    public function testDeleteErasesTheDocumentFileFromDisk(): void
    {
        $absolute = $this->writeSourceImage('ged/2026/05/lonely.png', 10, 10);
        $document = $this->makeImageDocument('ged/2026/05/lonely.png');

        $this->manager->delete($document);

        self::assertFileDoesNotExist($absolute);
    }

    public function testDeleteErasesTheGeneratedThumbnailToo(): void
    {
        $this->writeSourceImage('ged/2026/05/contract.png', 10, 10);
        $thumbnail = $this->writeSourceImage('ged/thumbnails/2026/05/contract.png', 4, 4);
        $document = $this->makeImageDocument('ged/2026/05/contract.png');
        $document->setThumbnailPath('ged/thumbnails/2026/05/contract.png');

        $this->manager->delete($document);

        self::assertFileDoesNotExist($thumbnail);
    }

    public function testDeleteErasesTheFilesOfEveryVersionRow(): void
    {
        // Version rows go away through an ON DELETE CASCADE, so their paths
        // have to be read before the flush or the bytes are unreachable.
        $old = $this->writeSourceImage('ged/2026/04/draft-v1.png', 10, 10);
        $current = $this->writeSourceImage('ged/2026/05/draft-v2.png', 10, 10);
        $document = $this->makeImageDocument('ged/2026/05/draft-v2.png');

        $this->versionRepository->method('findByDocument')->willReturn([
            $this->makeVersion('ged/2026/05/draft-v2.png'),
            $this->makeVersion('ged/2026/04/draft-v1.png'),
        ]);

        $this->manager->delete($document);

        self::assertFileDoesNotExist($old);
        self::assertFileDoesNotExist($current);
    }

    public function testDeleteSparesAFileAnotherDocumentStillPointsAt(): void
    {
        $shared = $this->writeSourceImage('ged/2026/05/shared.png', 10, 10);
        $document = $this->makeImageDocument('ged/2026/05/shared.png');

        // A surviving row still names this path - erasing it would break
        // whatever that row serves.
        $this->documentRepository->method('filterPathsInUse')
            ->willReturn(['ged/2026/05/shared.png']);

        $this->manager->delete($document);

        self::assertFileExists($shared);
    }

    public function testDeleteSparesAFileAVersionRowStillPointsAt(): void
    {
        $shared = $this->writeSourceImage('ged/2026/05/kept.png', 10, 10);
        $document = $this->makeImageDocument('ged/2026/05/kept.png');

        $this->versionRepository->method('filterPathsInUse')
            ->willReturn(['ged/2026/05/kept.png']);

        $this->manager->delete($document);

        self::assertFileExists($shared);
    }

    public function testDeleteReadsTheOwnedPathsBeforeTheRowIsRemoved(): void
    {
        // The guard query must see the deletion already committed, otherwise
        // the row being deleted answers for itself and nothing is ever erased.
        $absolute = $this->writeSourceImage('ged/2026/05/ordered.png', 10, 10);
        $document = $this->makeImageDocument('ged/2026/05/ordered.png');

        $calls = [];
        $this->entityManager->method('flush')->willReturnCallback(
            static function () use (&$calls): void { $calls[] = 'flush'; }
        );
        $this->documentRepository->method('filterPathsInUse')->willReturnCallback(
            static function (array $paths) use (&$calls): array {
                $calls[] = 'guard';

                return [];
            }
        );

        $this->manager->delete($document);

        self::assertSame(['flush', 'guard'], $calls);
        self::assertFileDoesNotExist($absolute);
    }

    public function testBulkDeleteErasesTheFilesOfEveryDocument(): void
    {
        $first = $this->writeSourceImage('ged/2026/05/one.png', 10, 10);
        $second = $this->writeSourceImage('ged/2026/05/two.png', 10, 10);

        $this->documentRepository->method('findBy')->willReturn([
            $this->makeImageDocument('ged/2026/05/one.png'),
            $this->makeImageDocument('ged/2026/05/two.png'),
        ]);

        self::assertSame(2, $this->manager->bulkDelete([1, 2]));
        self::assertFileDoesNotExist($first);
        self::assertFileDoesNotExist($second);
    }

    // --- cropImage() ---

    public function testCropImageWritesNewFileAndKeepsOriginal(): void
    {
        $source = $this->writeSourceImage('ged/2026/05/orig.png', 40, 40);
        $document = $this->makeImageDocument('ged/2026/05/orig.png');

        $this->manager->cropImage($document, 0, 0, 20, 20);

        // Document now points at a fresh file with the cropped dimensions…
        self::assertNotSame('ged/2026/05/orig.png', $document->getFilePath());
        self::assertSame(20, $document->getWidth());
        self::assertSame(20, $document->getHeight());
        // …and the original bytes are still on disk for the prior version.
        self::assertFileExists($source);
    }

    public function testCropImageRecordsTheCroppedFileAsNewVersion(): void
    {
        $this->writeSourceImage('ged/2026/05/snap.png', 30, 30);
        $document = $this->makeImageDocument('ged/2026/05/snap.png');

        $capturedVersion = null;
        $this->captureDocumentVersion($capturedVersion);

        $this->manager->cropImage($document, 0, 0, 15, 15);

        self::assertInstanceOf(DocumentVersion::class, $capturedVersion);
        self::assertSame($document->getFilePath(), $capturedVersion->getFilePath());
    }

    public function testCropImageIsNoopForNonImageDocument(): void
    {
        $document = new Document();
        $document->setTitle('Contract')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/2026/05/contract.pdf')
            ->setMimeType('application/pdf');

        $this->manager->cropImage($document, 0, 0, 10, 10);

        self::assertSame('ged/2026/05/contract.pdf', $document->getFilePath());
    }

    public function testRecordingVersionPrunesVersionsBeyondTheLimit(): void
    {
        $old1 = $this->createStub(DocumentVersionInterface::class);
        $old1->method('getFilePath')->willReturn('ged/2026/05/old-1.pdf');
        $old2 = $this->createStub(DocumentVersionInterface::class);
        $old2->method('getFilePath')->willReturn('ged/2026/05/old-2.pdf');
        $this->versionRepository->method('findPrunable')->willReturn([$old1, $old2]);

        $removed = [];
        $this->entityManager->method('remove')->willReturnCallback(
            static function (object $entity) use (&$removed): void {
                $removed[] = $entity;
            }
        );

        // create() with a file records a version, which triggers pruning.
        $this->manager->create($this->makeInput(
            filePath: 'ged/2026/05/current.pdf',
            fileName: 'current.pdf',
            originalName: 'Current.pdf',
            mimeType: 'application/pdf',
            size: 1000,
        ));

        self::assertContains($old1, $removed);
        self::assertContains($old2, $removed);
    }

    private function makeImageDocument(string $filePath): Document
    {
        $document = new Document();
        $document->setTitle('Photo')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath($filePath)
            ->setFileName('orig.png')
            ->setOriginalName('orig.png')
            ->setMimeType('image/png')
            ->setSize(100);

        return $document;
    }

    private function makeVersion(string $filePath): DocumentVersionInterface
    {
        $version = $this->createStub(DocumentVersionInterface::class);
        $version->method('getFilePath')->willReturn($filePath);

        return $version;
    }

    private function writeSourceImage(string $relativePath, int $width, int $height): string
    {
        $absolute = $this->workDir.'/'.$relativePath;
        mkdir(dirname($absolute), 0o777, true);
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $absolute);
        imagedestroy($image);

        return $absolute;
    }
}
