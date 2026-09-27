<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function count;
use function uniqid;

/**
 * Serving a file reads its document once.
 *
 * The access guard asked for the row's status, the locator for its disk: two
 * queries on the same row for every picture served from R2.
 */
final class DocumentPathLookupTest extends IntegrationTestCase
{
    private ?int $documentId = null;

    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        if (null !== $this->documentId) {
            $document = $entityManager->find(Document::class, $this->documentId);
            if (null !== $document) {
                $entityManager->remove($document);
                $entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testStatusAndDiskOfOnePathCostOneQuery(): void
    {
        static::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $path = 'ged/9999/01/lookup-'.uniqid().'.jpg';
        $document = new Document()
            ->setTitle('Lookup')
            ->setStatus(DocumentStatusEnum::Published)
            ->setFilePath($path)
            ->setFileName('lookup.jpg')
            ->setOriginalName('lookup.jpg')
            ->setMimeType('image/jpeg')
            ->setSize(1);
        $entityManager->persist($document);
        $entityManager->flush();
        $this->documentId = (int) $document->getId();

        $repository = static::getContainer()->get(DocumentRepository::class);
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        self::assertSame(DocumentStatusEnum::Published, $repository->findStatusForPath($path));
        self::assertInstanceOf(StorageDiskEnum::class, $repository->findStorageDiskForPath($path));
        self::assertSame(1, count($holder->getData()['default'] ?? []));

        // A worker forgets it between two messages.
        $repository->reset();
        $repository->findStatusForPath($path);
        self::assertSame(2, count($holder->getData()['default'] ?? []));
    }
}
