<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A sweeper that deletes files is only as good as what it refuses to delete.
 * Each test here is one thing it must not touch.
 */
final class PruneOrphanFilesCommandTest extends IntegrationTestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $this->uploadDir = (string) static::getContainer()->getParameter('app.upload_dir');
    }

    public function testAnOldUnreferencedFileIsReportedThenDeleted(): void
    {
        $orphan = $this->writeFile('ged/9990/01/orphan-'.uniqid().'.png', ancient: true);

        $tester = $this->runCommand([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Nothing deleted', $tester->getDisplay());
        self::assertFileExists($orphan, 'A dry run must not delete anything.');

        $this->runCommand(['--force' => true])->assertCommandIsSuccessful();
        self::assertFileDoesNotExist($orphan);
    }

    public function testAFileADocumentPointsAtIsNeverTouched(): void
    {
        $relative = 'ged/9990/02/kept-'.uniqid().'.png';
        $absolute = $this->writeFile($relative, ancient: true);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $document = new Document();
        $document->setTitle('Kept')->setStatus(DocumentStatusEnum::Draft)->setFilePath($relative);
        $entityManager->persist($document);
        $entityManager->flush();

        $this->runCommand(['--force' => true])->assertCommandIsSuccessful();

        self::assertFileExists($absolute);
    }

    public function testAVariantOfALiveDocumentIsNeverTouched(): void
    {
        $relative = 'ged/9990/03/source-'.uniqid().'.png';
        $variant = 'ged/9990/03/variants/medium/source.webp';
        $this->writeFile($relative, ancient: true);
        $variantFile = $this->writeFile($variant, ancient: true);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $document = new Document();
        $document->setTitle('With variants')
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath($relative)
            ->setVariants(['medium' => $variant]);
        $entityManager->persist($document);
        $entityManager->flush();

        $this->runCommand(['--force' => true])->assertCommandIsSuccessful();

        self::assertFileExists($variantFile);
    }

    public function testARecentUnreferencedFileIsSpared(): void
    {
        // The upload endpoint writes the bytes before the form is submitted.
        // A file with no row can simply be a form somebody has open.
        $fresh = $this->writeFile('ged/9990/04/fresh-'.uniqid().'.png', ancient: false);

        $this->runCommand(['--force' => true])->assertCommandIsSuccessful();

        self::assertFileExists($fresh);
    }

    /** @param array<string, bool|string> $input */
    private function runCommand(array $input): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('aurora:ged:prune-orphans'));
        $tester->execute($input);

        return $tester;
    }

    private function writeFile(string $relativePath, bool $ancient): string
    {
        $absolute = $this->uploadDir.'/'.$relativePath;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0o777, true);
        }

        file_put_contents($absolute, 'x');
        if ($ancient) {
            touch($absolute, time() - (30 * 86400));
        }

        return $absolute;
    }
}
