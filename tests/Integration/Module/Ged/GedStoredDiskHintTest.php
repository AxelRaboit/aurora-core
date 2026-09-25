<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Storage\GedStoredDiskHint;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The media library tells the file locator where its files are, so a picture
 * on a remote disk is served without first asking that disk whether it exists.
 */
final class GedStoredDiskHintTest extends IntegrationTestCase
{
    private GedStoredDiskHint $hint;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->hint = static::getContainer()->get(GedStoredDiskHint::class);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $document = new Document();
        $document->setTitle('Distante')->setMimeType('image/png')
            ->setFilePath('ged/2026/09/distante-4f2a.png')
            ->setStorageDisk(StorageDiskEnum::R2);
        $entityManager->persist($document);
        $entityManager->flush();
    }

    public function testASourceAndItsVariantsAnswerTheirDocumentsDisk(): void
    {
        self::assertTrue($this->hint->supports('ged/2026/09/distante-4f2a.png'));
        self::assertSame(StorageDiskEnum::R2, $this->hint->diskFor('ged/2026/09/distante-4f2a.png'));
        self::assertSame(StorageDiskEnum::R2, $this->hint->diskFor('ged/2026/09/variants/xlarge/distante-4f2a.webp'));
    }

    public function testAPathNoDocumentOwnsHasNoAnswer(): void
    {
        self::assertNull($this->hint->diskFor('ged/2026/09/personne.png'));
        self::assertFalse($this->hint->supports('notes/image.webp'));
    }
}
