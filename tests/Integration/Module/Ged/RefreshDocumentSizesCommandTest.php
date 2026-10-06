<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function str_repeat;
use function uniqid;

/**
 * Catching up on the sizes already recorded.
 *
 * A document's size is taken when the file arrives, and a JPEG source is
 * re-encoded in place when its generated sizes are produced: the number
 * stopped being true one line later. The manager now reads it again, so
 * nothing new comes in wrong; this command is for what is already there, and
 * a library full of wrong sizes is a library whose every total is wrong.
 */
final class RefreshDocumentSizesCommandTest extends IntegrationTestCase
{
    private string $uploadDir;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $this->uploadDir = (string) static::getContainer()->getParameter('app.upload_dir');
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testARecordedSizeThatLiesIsPutBackOnTheStoredFile(): void
    {
        $document = $this->givenDocument(stored: 120, recorded: 999_999);

        $this->runCommand([])->assertCommandIsSuccessful();
        $this->entityManager->refresh($document);

        self::assertSame(120, $document->getSize());
    }

    public function testADryRunCountsAndWritesNothing(): void
    {
        $document = $this->givenDocument(stored: 120, recorded: 999_999);

        $tester = $this->runCommand(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('would change', $tester->getDisplay());

        $this->entityManager->refresh($document);
        self::assertSame(999_999, $document->getSize());
    }

    /**
     * A missing file is not a size to correct, it is another problem - and
     * overwriting it with zero would hide it.
     */
    public function testADocumentWhoseFileIsGoneKeepsItsRecordedSize(): void
    {
        $document = $this->givenDocument(stored: null, recorded: 4242);

        $this->runCommand([])->assertCommandIsSuccessful();
        $this->entityManager->refresh($document);

        self::assertSame(4242, $document->getSize());
    }

    private function givenDocument(?int $stored, int $recorded): Document
    {
        $relative = 'ged/9991/01/size-'.uniqid().'.bin';

        if (null !== $stored) {
            $absolute = $this->uploadDir.'/'.$relative;

            if (!is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0o777, true);
            }

            file_put_contents($absolute, str_repeat('x', $stored));
        }

        $document = new Document();
        $document
            ->setTitle('Poids à vérifier')
            ->setStatus(DocumentStatusEnum::Published)
            ->setFilePath($relative)
            ->setFileName('size.bin')
            ->setMimeType('application/octet-stream')
            ->setSize($recorded);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    /** @param array<string, mixed> $input */
    private function runCommand(array $input): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('aurora:ged:sizes:refresh'));
        $tester->execute($input);

        return $tester;
    }
}
