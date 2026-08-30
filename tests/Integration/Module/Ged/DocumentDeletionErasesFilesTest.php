<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentVersion;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

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
    public function testDeletingADocumentErasesItsFileAndItsVersionFiles(): void
    {
        static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(DocumentManagerInterface::class);
        $uploadDir = (string) $container->getParameter('app.upload_dir');

        $livePath = 'ged/9999/01/live-'.uniqid().'.png';
        $previousPath = 'ged/9999/01/previous-'.uniqid().'.png';
        $liveFile = $this->writePng($uploadDir, $livePath);
        $previousFile = $this->writePng($uploadDir, $previousPath);

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

        $manager->delete($document);

        self::assertFileDoesNotExist($liveFile);
        self::assertFileDoesNotExist($previousFile);
    }

    private function writePng(string $uploadDir, string $relativePath): string
    {
        $absolute = $uploadDir.'/'.$relativePath;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0o777, true);
        }

        $image = imagecreatetruecolor(8, 8);
        imagepng($image, $absolute);
        imagedestroy($image);

        return $absolute;
    }
}
