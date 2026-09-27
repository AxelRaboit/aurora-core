<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Dto\DocumentInput;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function count;
use function str_contains;
use function uniqid;

/**
 * A new document is written with its first version in one go.
 *
 * It asked the database for the next version number and for versions to
 * prune - of a document born a moment ago, which has neither - and flushed
 * once for the document and once more for the version. An import pays that
 * per file.
 */
final class DocumentCreationQueriesTest extends IntegrationTestCase
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

    public function testANewDocumentGetsItsFirstVersionWithoutAskingForIt(): void
    {
        static::bootKernel();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $path = 'ged/9999/01/creation-'.uniqid().'.pdf';
        $document = static::getContainer()->get(DocumentManagerInterface::class)->create(new DocumentInput(
            title: 'Création',
            status: DocumentStatusEnum::Draft,
            filePath: $path,
            fileName: 'creation.pdf',
            originalName: 'creation.pdf',
            mimeType: 'application/pdf',
            size: 10,
        ));
        $this->documentId = (int) $document->getId();

        $versions = static::getContainer()->get(DocumentVersionRepository::class)->findByDocument($document);
        self::assertCount(1, $versions);
        self::assertSame(1, $versions[0]->getVersionNumber());
        self::assertSame($path, $versions[0]->getFilePath());

        $asked = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], 'MAX(')
                && str_contains((string) $query['sql'], 'core_ged_document_versions'),
        );
        self::assertSame(0, count($asked), 'the first version number is not asked for');
    }
}
