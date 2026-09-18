<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentVersion;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Replacing the file of a document that is already published.
 *
 * What the command promises is that the row survives: the pages pointing at
 * this document keep pointing at it, the words written about the picture stay
 * written, and the file it used to carry becomes a version instead of
 * disappearing. Those are the assertions - the upload itself is the import
 * command's road and is tested next door.
 */
final class ReplaceDocumentFileCommandTest extends IntegrationTestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();

        $this->sourceDir = sys_get_temp_dir().'/aurora_replace_'.uniqid();
        mkdir($this->sourceDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->sourceDir);

        parent::tearDown();
    }

    public function testTheDocumentKeepsItsIdentityAndItsWords(): void
    {
        $document = $this->imported('avant.png', 24, 16);
        $id = (int) $document->getId();
        $before = (string) $document->getFilePath();

        $document->setAlt('La discussion d\'un espace, en direct.')
            ->setCaption('La discussion : ce qui ne tient sur aucune fiche')
            ->setStatus(DocumentStatusEnum::Published);
        $this->entityManager()->flush();

        $this->writeImage('apres.png', 48, 32);
        $this->runReplace($id, $this->sourceDir.'/apres.png')->assertCommandIsSuccessful();

        $this->entityManager()->clear();
        $fresh = $this->entityManager()->find(Document::class, $id);

        self::assertNotNull($fresh, 'Replacing swaps the file, it does not swap the document.');
        self::assertNotSame($before, $fresh->getFilePath(), 'A new file means a new address.');
        self::assertSame(48, $fresh->getWidth(), 'The dimensions follow the new file.');
        self::assertSame('La discussion d\'un espace, en direct.', $fresh->getAlt());
        self::assertSame('La discussion : ce qui ne tient sur aucune fiche', $fresh->getCaption());
        self::assertSame(DocumentStatusEnum::Published, $fresh->getStatus());
        self::assertNotEmpty($fresh->getVariants(), 'The variants are rebuilt for the new file.');

        $versions = $this->entityManager()->getRepository(DocumentVersion::class)
            ->findBy(['document' => $fresh]);
        $paths = array_map(static fn (DocumentVersion $v): string => $v->getFilePath(), $versions);

        self::assertContains($before, $paths, 'The file it used to carry is kept as a version.');
    }

    public function testAnUnknownDocumentIsRefusedRatherThanCreated(): void
    {
        $this->writeImage('orpheline.png', 8, 8);

        $tester = $this->runReplace(987654321, $this->sourceDir.'/orpheline.png');

        self::assertNotSame(0, $tester->getStatusCode());
    }

    public function testADryRunWritesNothing(): void
    {
        $document = $this->imported('stable.png', 24, 16);
        $id = (int) $document->getId();
        $before = (string) $document->getFilePath();

        $this->writeImage('ignoree.png', 48, 32);
        $this->runReplace($id, $this->sourceDir.'/ignoree.png', ['--dry-run' => true])
            ->assertCommandIsSuccessful();

        $this->entityManager()->clear();

        self::assertSame($before, $this->entityManager()->find(Document::class, $id)?->getFilePath());
    }

    /** @param array<string, bool|string> $options */
    private function runReplace(int $id, string $path, array $options = []): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('aurora:ged:replace'));
        $tester->execute(['document' => (string) $id, 'file' => $path] + $options);

        return $tester;
    }

    /** The document to replace, put there the way a real one arrives. */
    private function imported(string $name, int $width, int $height): Document
    {
        $this->writeImage($name, $width, $height);

        $application = new Application(static::$kernel);
        new CommandTester($application->find('aurora:ged:import'))
            ->execute(['paths' => [$this->sourceDir.'/'.$name]]);

        $document = $this->entityManager()->getRepository(Document::class)
            ->findOneBy([], ['id' => 'DESC']);

        self::assertInstanceOf(Document::class, $document);

        return $document;
    }

    private function writeImage(string $name, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $this->sourceDir.'/'.$name);
        imagedestroy($image);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
