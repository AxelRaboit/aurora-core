<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The claim this command makes is that a file imported from the shell is
 * indistinguishable from one dropped in the médiathèque. That is the claim
 * worth testing, so the assertions are about the things a hand-written INSERT
 * would have left empty: the reference, the responsive variants, the stored
 * bytes.
 */
final class ImportDocumentsCommandTest extends IntegrationTestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();

        $this->sourceDir = sys_get_temp_dir().'/aurora_import_'.uniqid();
        mkdir($this->sourceDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->sourceDir);

        parent::tearDown();
    }

    public function testAnImportedImageIsAWholeDocument(): void
    {
        $this->writeImage('AXL00594-Modifier.png');

        $tester = $this->runImport([$this->sourceDir]);
        $tester->assertCommandIsSuccessful();

        $document = $this->lastDocument();

        self::assertNotNull($document);
        // The filename, made readable: separators to spaces, extension gone,
        // and the photographer's own capitalisation left alone.
        self::assertSame('AXL00594 Modifier', $document->getTitle());
        self::assertSame(DocumentStatusEnum::Published, $document->getStatus());
        self::assertSame('AXL00594-Modifier.png', $document->getOriginalName());
        self::assertNotNull($document->getReference(), 'An imported document is numbered like any other.');
        self::assertNotEmpty($document->getVariants(), 'An imported image carries its responsive variants.');
        self::assertSame(24, $document->getWidth());
        self::assertSame(16, $document->getHeight());

        $uploadDir = (string) static::getContainer()->getParameter('app.upload_dir');
        self::assertFileExists($uploadDir.'/'.$document->getFilePath());
    }

    public function testNoAltTextIsInvented(): void
    {
        $this->writeImage('a-black-cab-in-london.png');

        $this->runImport([$this->sourceDir])->assertCommandIsSuccessful();

        // A filename is a passable title and a bad description. A wrong alt is
        // worse than none, because it is read out with confidence.
        self::assertNull($this->lastDocument()?->getAlt());
    }

    public function testADirectoryIsImportedInNameOrder(): void
    {
        $this->writeImage('b-second.png');
        $this->writeImage('a-first.png');

        $tester = $this->runImport([$this->sourceDir]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();

        self::assertLessThan(
            mb_strpos($display, 'b second'),
            mb_strpos($display, 'a first'),
            'A folder imports in its own order, not in whatever order the filesystem answers in.',
        );
    }

    public function testADryRunStoresNothing(): void
    {
        $this->writeImage('not-imported.png');

        $before = $this->documentCount();
        $this->runImport([$this->sourceDir], ['--dry-run' => true])->assertCommandIsSuccessful();

        self::assertSame($before, $this->documentCount());
    }

    public function testAnUnknownStatusIsRefusedRatherThanGuessed(): void
    {
        $this->writeImage('whatever.png');

        $tester = $this->runImport([$this->sourceDir], ['--status' => 'live']);

        self::assertSame(CommandTester::class, $tester::class);
        self::assertNotSame(0, $tester->getStatusCode());
        self::assertSame(0, $this->countTitled('whatever'));
    }

    /**
     * @param list<string>               $paths
     * @param array<string, bool|string> $options
     */
    private function runImport(array $paths, array $options = []): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('aurora:ged:import'));
        $tester->execute(['paths' => $paths] + $options);

        return $tester;
    }

    private function writeImage(string $name): void
    {
        $image = imagecreatetruecolor(24, 16);
        imagepng($image, $this->sourceDir.'/'.$name);
        imagedestroy($image);
    }

    private function lastDocument(): ?Document
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Document::class)
            ->findOneBy([], ['id' => 'DESC']);
    }

    private function documentCount(): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(d.id) FROM '.Document::class.' d')
            ->getSingleScalarResult();
    }

    private function countTitled(string $title): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(d.id) FROM '.Document::class.' d WHERE d.title LIKE :title')
            ->setParameter('title', $title.'%')
            ->getSingleScalarResult();
    }
}
