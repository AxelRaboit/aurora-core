<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentVersion;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Document\Message\EraseDocumentFilesMessage;
use Aurora\Module\Ged\Document\MessageHandler\EraseDocumentFilesHandler;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The whole deletion chain, with nothing mocked: real container, real schema,
 * real files, real `ON DELETE CASCADE` on the version rows.
 *
 * The manager's unit test proves the logic and the repository test proves the
 * two guard queries, but both stop short of the one thing that actually broke
 * here - a version row disappearing under the manager before its path could
 * be read. Only a real cascade shows that.
 */
final class DocumentDeletionErasesFilesTest extends IntegrationTestCase
{
    public function testDestroyingADocumentErasesItsFileAndItsVersionFiles(): void
    {
        static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(DocumentManagerInterface::class);
        $uploadDirectory = (string) $container->getParameter('app.upload_dir');

        $livePath = 'ged/9999/01/live-'.uniqid().'.png';
        $previousPath = 'ged/9999/01/previous-'.uniqid().'.png';
        $liveFile = $this->writePng($uploadDirectory, $livePath);
        $previousFile = $this->writePng($uploadDirectory, $previousPath);

        $document = new Document();
        $document->setTitle('Deletion probe')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath($livePath)
            ->setFileName(basename($livePath))
            ->setOriginalName('probe.png')
            ->setMimeType('image/png')
            ->setSize(1);
        $entityManager->persist($document);

        $version = new DocumentVersion();
        $version->setDocument($document)
            ->setFilePath($previousPath)
            ->setFileName(basename($previousPath))
            ->setOriginalName('probe.png')
            ->setMimeType('image/png')
            ->setSize(1)
            ->setVersionNumber(1);
        $entityManager->persist($version);
        $entityManager->flush();

        self::assertFileExists($liveFile);
        self::assertFileExists($previousFile);

        // Trashing first, because that is now what the delete button does, and
        // the bytes have to survive it: this is the half a restore depends on.
        $manager->delete($document);

        self::assertTrue($document->isTrashed());
        self::assertFileExists($liveFile);
        self::assertFileExists($previousFile);

        $manager->forceDelete($document);

        // The row is gone, the bytes wait for the worker: the request does
        // not pay for the storage calls.
        self::assertFileExists($liveFile);
        self::assertFileExists($previousFile);

        $this->runQueue();

        self::assertFileDoesNotExist($liveFile);
        self::assertFileDoesNotExist($previousFile);
    }

    /**
     * Emptying a trash that holds several documents, one of them versioned.
     *
     * The shape production failed on, and the one the single-document test
     * above cannot show: `destroy()` audits inside its loop, the audit writes
     * a row and flushes, and that flush lands while an earlier document is
     * already scheduled for removal. The earlier document leaves the unit of
     * work; its version rows, loaded a line before to read their paths, stay
     * behind pointing at it - and the closing flush stops on "a new entity was
     * found through the relationship DocumentVersion#document".
     *
     * Two documents at least, and the versioned one first: with one document
     * there is no second audit to flush in the middle, and with no version
     * there is nothing left holding the reference.
     */
    public function testEmptyingATrashHoldingAVersionedDocumentErasesEverything(): void
    {
        static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(DocumentManagerInterface::class);
        $uploadDirectory = (string) $container->getParameter('app.upload_dir');

        $versionedPath = 'ged/9999/01/versioned-'.uniqid().'.png';
        $previousPath = 'ged/9999/01/previous-'.uniqid().'.png';
        $plainPath = 'ged/9999/01/plain-'.uniqid().'.png';

        $versionedFile = $this->writePng($uploadDirectory, $versionedPath);
        $previousFile = $this->writePng($uploadDirectory, $previousPath);
        $plainFile = $this->writePng($uploadDirectory, $plainPath);

        $versioned = $this->document('Versioned probe', $versionedPath);
        $entityManager->persist($versioned);

        $version = new DocumentVersion();
        $version->setDocument($versioned)
            ->setFilePath($previousPath)
            ->setFileName(basename($previousPath))
            ->setOriginalName('probe.png')
            ->setMimeType('image/png')
            ->setSize(1)
            ->setVersionNumber(1);
        $entityManager->persist($version);

        $plain = $this->document('Plain probe', $plainPath);
        $entityManager->persist($plain);
        $entityManager->flush();

        $manager->delete($versioned);
        $manager->delete($plain);

        self::assertSame(2, $manager->emptyTrash());

        $this->runQueue();

        self::assertFileDoesNotExist($versionedFile);
        self::assertFileDoesNotExist($previousFile);
        self::assertFileDoesNotExist($plainFile);
    }

    /**
     * Emptying a trash costs the same queries for four documents or four hundred.
     *
     * Each document read its versions on its own, and wrote its line of
     * audit with its own sequence number and its own flush - and each flush
     * recomputed everything the unit of work already held.
     */
    public function testEmptyingATrashReadsAndAuditsInBatches(): void
    {
        static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(DocumentManagerInterface::class);
        $uploadDirectory = (string) $container->getParameter('app.upload_dir');

        $manager->emptyTrash();

        foreach (['un', 'deux', 'trois', 'quatre'] as $name) {
            $path = 'ged/9999/01/batch-'.$name.'-'.uniqid().'.png';
            $this->writePng($uploadDirectory, $path);
            $document = $this->document('Batch '.$name, $path);
            $entityManager->persist($document);
            $entityManager->flush();
            $manager->delete($document);
        }

        $holder = $container->get('doctrine.debug_data_holder');
        $holder->reset();

        self::assertSame(4, $manager->emptyTrash());

        $count = static fn (string $needle): int => count(array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], $needle),
        ));
        self::assertSame(1, $count('app_sequence_counters'), 'the audit references are reserved together');
        self::assertSame(1, $count('FROM core_ged_document_versions c0_ WHERE c0_.document_id IN'), 'the versions are read together');
    }

    public function testRestoringATrashedDocumentBringsItBackWithItsFile(): void
    {
        static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(DocumentManagerInterface::class);
        $uploadDirectory = (string) $container->getParameter('app.upload_dir');

        $livePath = 'ged/9999/01/restore-'.uniqid().'.png';
        $liveFile = $this->writePng($uploadDirectory, $livePath);

        $document = (new Document())
            ->setTitle('Restore probe')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath($livePath)
            ->setFileName(basename($livePath))
            ->setOriginalName('probe.png')
            ->setMimeType('image/png')
            ->setSize(1);
        $entityManager->persist($document);
        $entityManager->flush();

        $manager->delete($document);
        $manager->restore($document);

        self::assertFalse($document->isTrashed());
        self::assertFileExists($liveFile);
    }

    /**
     * Stands in for the worker. The handler is invoked directly rather than
     * through the bus: what is under test is what the queued work does, and
     * going through the bus would only re-queue it.
     */
    private function runQueue(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $handler = static::getContainer()->get(EraseDocumentFilesHandler::class);

        $handled = 0;
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EraseDocumentFilesMessage) {
                $handler($message);
                ++$handled;
            }
        }

        self::assertGreaterThan(0, $handled, 'the erasure was queued for the worker');
    }

    private function document(string $title, string $path): Document
    {
        return new Document()
            ->setTitle($title)
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath($path)
            ->setFileName(basename($path))
            ->setOriginalName('probe.png')
            ->setMimeType('image/png')
            ->setSize(1);
    }

    private function writePng(string $uploadDirectory, string $relativePath): string
    {
        $absolute = $uploadDirectory.'/'.$relativePath;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0o777, true);
        }

        $image = imagecreatetruecolor(8, 8);
        imagepng($image, $absolute);
        imagedestroy($image);

        return $absolute;
    }
}
