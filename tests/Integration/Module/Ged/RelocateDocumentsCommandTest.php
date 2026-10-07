<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Moving a set of documents to one disk from the console.
 *
 * The move itself is DocumentRelocator's, tested on its own; what this
 * command promises is to touch only what it was told, to refuse when it was
 * told nothing, and to leave alone a document already in place. The test
 * environment has only the local disk, so that is the disk the assertions
 * ask for.
 */
final class RelocateDocumentsCommandTest extends IntegrationTestCase
{
    private string $sourceDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();

        $this->sourceDirectory = sys_get_temp_dir().'/aurora_relocate_'.uniqid();
        mkdir($this->sourceDirectory, 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->sourceDirectory);

        parent::tearDown();
    }

    public function testNothingAskedIsRefusedRatherThanTheWholeLibraryMoved(): void
    {
        $tester = $this->runRelocate(['disk' => 'local']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('--category', $tester->getDisplay());
    }

    public function testAnUnknownDiskIsRefused(): void
    {
        $tester = $this->runRelocate(['disk' => 'floppy', '--id' => ['1']]);

        self::assertSame(2, $tester->getStatusCode());
    }

    public function testADocumentAlreadyOnTheDiskIsLeftAlone(): void
    {
        $document = $this->imported('deja.png');
        self::assertSame(StorageDiskEnum::Local, $document->getStorageDisk());

        $tester = $this->runRelocate(['disk' => 'local', '--id' => [(string) $document->getId()]]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 document(s), 0 to move', $tester->getDisplay());
    }

    /** @param array<string, mixed> $input */
    private function runRelocate(array $input): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('aurora:ged:relocate'));
        $tester->execute($input);

        return $tester;
    }

    private function imported(string $name): Document
    {
        $image = imagecreatetruecolor(16, 16);
        imagepng($image, $this->sourceDirectory.'/'.$name);
        imagedestroy($image);

        $application = new Application(static::$kernel);
        new CommandTester($application->find('aurora:ged:import'))
            ->execute(['paths' => [$this->sourceDirectory.'/'.$name]]);

        $document = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Document::class)
            ->findOneBy([], ['id' => 'DESC']);

        self::assertInstanceOf(Document::class, $document);

        return $document;
    }
}
