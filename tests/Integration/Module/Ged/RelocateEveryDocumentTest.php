<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Tests\Integration\Core\Storage\DocumentRelocatorTest;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which documents "move everything to that backend" actually picks up.
 *
 * The move itself is somebody else's suite - {@see DocumentRelocatorTest}
 * owns the ordering, the locking and the bytes. What matters here is the
 * selection, because it is the part an administrator cannot check: they press
 * one button and are told a number, and the only way to know the number was
 * right is for this to say so.
 */
final class RelocateEveryDocumentTest extends IntegrationTestCase
{
    private DocumentRepository $documents;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documents = self::getContainer()->get(DocumentRepository::class);
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
    }

    public function testItPicksUpOnlyWhatIsNotThereYet(): void
    {
        $here = $this->makeDocument(StorageDiskEnum::Local);
        $there = $this->makeDocument(StorageDiskEnum::R2);

        $ids = $this->documents->idsNotOnDisk(StorageDiskEnum::R2);

        self::assertContains((int) $here->getId(), $ids);
        self::assertNotContains(
            (int) $there->getId(),
            $ids,
            'a document already on the target costs a message and a round trip to be told it has nothing to do',
        );
    }

    /**
     * The trash is on its way out - the retention sweep deletes the bytes -
     * so copying it to a metered bucket first would be paying to store what is
     * about to be thrown away.
     */
    public function testItLeavesTheTrashWhereItIs(): void
    {
        $trashed = $this->makeDocument(StorageDiskEnum::Local);
        $trashed->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        self::assertNotContains(
            (int) $trashed->getId(),
            $this->documents->idsNotOnDisk(StorageDiskEnum::R2),
        );
    }

    /**
     * And the count the reader is shown next to it counts the same population.
     * The plain `countOnDisk` deliberately counts the trash too, because it
     * guards disconnecting a backend; reporting with it here would tell an
     * administrator that documents they cannot see were "already there".
     */
    public function testTheAlreadyThereCountIgnoresTheTrashToo(): void
    {
        $before = $this->documents->countLivingOnDisk(StorageDiskEnum::R2);

        $trashed = $this->makeDocument(StorageDiskEnum::R2);
        $trashed->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        self::assertSame($before, $this->documents->countLivingOnDisk(StorageDiskEnum::R2));
        self::assertSame(
            $before + 1,
            $this->documents->countOnDisk(StorageDiskEnum::R2),
            'the guarding count still sees it, which is the difference between the two',
        );
    }

    private function makeDocument(StorageDiskEnum $disk): DocumentInterface
    {
        $suffix = bin2hex(random_bytes(5));

        $document = new Document();
        $document->setTitle('Document a basculer')
            ->setReference('ALL-'.bin2hex(random_bytes(3)))
            ->setFilePath('ged/2026/09/all-'.$suffix.'.png')
            ->setMimeType('image/png')
            ->setSize(20)
            ->setStorageDisk($disk);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }
}
