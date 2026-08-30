<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentVersion;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The two queries that decide whether deleting a document may erase its bytes.
 *
 * They are mocked out in the manager's unit test, so nothing there would
 * notice a field rename or invalid DQL - and the cost of that going unnoticed
 * is either a leaked file forever or, worse, a file taken out from under a row
 * that still serves it.
 */
final class DocumentFilePathsInUseTest extends IntegrationTestCase
{
    public function testDocumentPathsAreReportedThroughFilePathAndThumbnail(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(DocumentRepository::class);

        $document = new Document();
        $document->setTitle('In use')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/1999/01/live.png')
            ->setThumbnailPath('ged/thumbnails/1999/01/live.png');
        $entityManager->persist($document);
        $entityManager->flush();

        $inUse = $repository->filterPathsInUse([
            'ged/1999/01/live.png',
            'ged/thumbnails/1999/01/live.png',
            'ged/1999/01/nobody.png',
        ]);

        sort($inUse);
        self::assertSame(
            ['ged/1999/01/live.png', 'ged/thumbnails/1999/01/live.png'],
            $inUse,
        );
    }

    public function testVersionPathsAreReported(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(DocumentVersionRepository::class);

        $document = new Document();
        $document->setTitle('Versioned')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/1999/02/current.png');
        $entityManager->persist($document);

        $version = new DocumentVersion();
        $version->setDocument($document)
            ->setFilePath('ged/1999/02/previous.png')
            ->setFileName('previous.png')
            ->setOriginalName('previous.png')
            ->setMimeType('image/png')
            ->setSize(10)
            ->setVersionNumber(1);
        $entityManager->persist($version);
        $entityManager->flush();

        self::assertSame(
            ['ged/1999/02/previous.png'],
            $repository->filterPathsInUse(['ged/1999/02/previous.png', 'ged/1999/02/nobody.png']),
        );
    }

    public function testEmptyInputShortCircuitsWithoutQuerying(): void
    {
        static::createClient();

        self::assertSame([], static::getContainer()->get(DocumentRepository::class)->filterPathsInUse([]));
        self::assertSame([], static::getContainer()->get(DocumentVersionRepository::class)->filterPathsInUse([]));
    }
}
