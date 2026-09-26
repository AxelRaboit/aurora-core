<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Core\Storage;

use Aurora\Core\Storage\ActiveStorageDiskProviderInterface;
use Aurora\Core\Storage\Adapter\LocalStorageAdapter;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;
use Aurora\Module\Ged\Document\Service\DocumentRelocator;
use Aurora\Module\Ged\Enum\DocumentTransferStateEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Moving a document's bytes, against two real local backends.
 *
 * Local to local rather than local to R2 on purpose: the interesting rules
 * here are about ordering, locking and which rows are updated, and none of
 * them care which backend is on the other side. Keeping the pair local means
 * these run in CI, every time, with no credentials. The R2 side of the same
 * contract is covered by its own suite.
 */
final class DocumentRelocatorTest extends IntegrationTestCase
{
    private string $primaryRoot;
    private string $secondaryRoot;
    private LocalStorageAdapter $primary;
    private SecondDiskAdapter $secondary;
    private DocumentRelocator $relocator;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $suffix = bin2hex(random_bytes(6));
        $this->primaryRoot = sys_get_temp_dir().'/aurora-reloc-primary-'.$suffix;
        $this->secondaryRoot = sys_get_temp_dir().'/aurora-reloc-secondary-'.$suffix;
        $this->filesystem->mkdir([$this->primaryRoot, $this->secondaryRoot]);

        $this->primary = new LocalStorageAdapter($this->filesystem, $this->primaryRoot);
        // A second adapter answering for R2, pointed at another directory. It
        // is not R2, and does not need to be: what is under test is the move,
        // not the backend.
        $this->secondary = new SecondDiskAdapter(
            new LocalStorageAdapter($this->filesystem, $this->secondaryRoot),
        );

        $manager = new StorageManager(
            [$this->primary, $this->secondary],
            new class implements ActiveStorageDiskProviderInterface {
                public function activeDisk(): StorageDiskEnum
                {
                    return StorageDiskEnum::Local;
                }
            },
        );

        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $this->relocator = new DocumentRelocator(
            $manager,
            new LocalWorkspace($this->filesystem),
            $entityManager,
            self::getContainer()->get(DocumentRepository::class),
            self::getContainer()->get(DocumentVersionRepository::class),
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove([$this->primaryRoot, $this->secondaryRoot]);

        parent::tearDown();
    }

    public function testItMovesEveryFileADocumentOwns(): void
    {
        $document = $this->makeDocument();

        $relocation = $this->relocator->relocate($document, StorageDiskEnum::R2);

        self::assertTrue($relocation->ok);
        self::assertFalse($relocation->alreadyThere);
        self::assertSame(5, $relocation->filesMoved, 'the file, its thumbnail and its three renditions');
        self::assertSame(StorageDiskEnum::R2, $document->getStorageDisk());

        foreach ($this->keysOf($document) as $key) {
            self::assertTrue($this->secondary->exists($key), sprintf('%s arrived', $key));
            self::assertFalse($this->primary->exists($key), sprintf('%s was freed at the source', $key));
        }
    }

    public function testTheBytesArriveIntact(): void
    {
        $document = $this->makeDocument();
        $key = (string) $document->getFilePath();
        $before = $this->primary->read($key);

        $this->relocator->relocate($document, StorageDiskEnum::R2);

        self::assertSame($before, $this->secondary->read($key));
    }

    public function testAskingForWhereItAlreadyIsIsASuccessThatMovesNothing(): void
    {
        $document = $this->makeDocument();

        $relocation = $this->relocator->relocate($document, StorageDiskEnum::Local);

        self::assertTrue($relocation->ok);
        self::assertTrue($relocation->alreadyThere);
        self::assertSame(0, $relocation->filesMoved);
    }

    /**
     * The lock is the displayed state, claimed with a conditional write, so a
     * second click finds the door shut rather than copying the same bytes
     * twice and racing over which deletion wins.
     */
    public function testADocumentAlreadyMovingRefusesASecondMove(): void
    {
        $document = $this->makeDocument();
        $document->setStorageTransferState(DocumentTransferStateEnum::Pending);
        self::getContainer()->get('doctrine')->getManager()->flush();

        $relocation = $this->relocator->relocate($document, StorageDiskEnum::R2);

        self::assertFalse($relocation->ok);
        self::assertTrue($relocation->busy);
        self::assertSame(StorageDiskEnum::Local, $document->getStorageDisk(), 'nothing moved');
    }

    public function testTheLockIsReleasedOnceTheMoveIsDone(): void
    {
        $document = $this->makeDocument();

        $this->relocator->relocate($document, StorageDiskEnum::R2);

        self::assertSame(DocumentTransferStateEnum::Idle, $document->getStorageTransferState());
        self::assertNull($document->getStorageTransferError());
    }

    public function testAMoveCanBeUndone(): void
    {
        $document = $this->makeDocument();
        $keys = $this->keysOf($document);

        $this->relocator->relocate($document, StorageDiskEnum::R2);
        $back = $this->relocator->relocate($document, StorageDiskEnum::Local);

        self::assertTrue($back->ok);
        self::assertSame(StorageDiskEnum::Local, $document->getStorageDisk());

        foreach ($keys as $key) {
            self::assertTrue($this->primary->exists($key));
            self::assertFalse($this->secondary->exists($key));
        }
    }

    /**
     * A path a row names and no backend holds is pre-existing damage rather
     * than something the move caused. Refusing to move a document because one
     * of its old versions lost its file helps nobody.
     */
    public function testAMissingFileIsSteppedOverRatherThanFatal(): void
    {
        $document = $this->makeDocument();
        $this->primary->delete((string) $document->getThumbnailPath());

        $relocation = $this->relocator->relocate($document, StorageDiskEnum::R2);

        self::assertTrue($relocation->ok);
        self::assertSame(4, $relocation->filesMoved, 'the four that were still there');
        self::assertSame(StorageDiskEnum::R2, $document->getStorageDisk());
    }

    /**
     * Two documents can name the same path. Freeing the source has to ask
     * whether anything left on that side still points at it, and the question
     * must be asked per backend: the paths do not change when a document
     * moves, so the plain form would answer yes about the row that just left.
     */
    public function testAPathAnotherDocumentStillUsesIsNotFreed(): void
    {
        $document = $this->makeDocument();
        $shared = (string) $document->getFilePath();

        $neighbour = new Document();
        $neighbour->setTitle('Le voisin')->setReference('VOISIN-'.bin2hex(random_bytes(3)));
        $neighbour->setFilePath($shared)->setMimeType('image/png')->setSize(12);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($neighbour);
        $entityManager->flush();

        $this->relocator->relocate($document, StorageDiskEnum::R2);

        self::assertTrue($this->secondary->exists($shared), 'the mover got its copy');
        self::assertTrue($this->primary->exists($shared), 'and the neighbour kept its file');
    }

    public function testWeighAddsUpEveryFileTheDocumentOwns(): void
    {
        $document = $this->makeDocument();

        self::assertGreaterThan(0, $this->relocator->weigh($document));
    }

    /** @return list<string> */
    private function keysOf(DocumentInterface $document): array
    {
        return $this->relocator->keysOf($document);
    }

    private function makeDocument(): DocumentInterface
    {
        $base = 'ged/2026/09/reloc-'.bin2hex(random_bytes(5));

        $renditions = [];
        foreach (['thumbnail', 'medium', 'large'] as $name) {
            $renditions[$name] = sprintf('%s/variants/%s.webp', dirname($base), $name.'-'.basename($base));
        }

        $document = new Document();
        $document->setTitle('Document a deplacer')
            ->setReference('RELOC-'.bin2hex(random_bytes(3)))
            ->setFilePath($base.'.png')
            ->setThumbnailPath($base.'-thumb.jpg')
            ->setRenditions($renditions)
            ->setMimeType('image/png')
            ->setSize(20);

        // Persisted before its keys are asked for: collecting them queries the
        // version table by document, which Doctrine cannot do for an entity
        // with no identifier yet.
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($document);
        $entityManager->flush();

        foreach ($this->keysOf($document) as $key) {
            $this->primary->write($key, 'contenu de '.$key);
        }

        return $document;
    }
}
