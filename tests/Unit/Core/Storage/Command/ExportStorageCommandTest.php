<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage\Command;

use Aurora\Core\Storage\ActiveStorageDiskProviderInterface;
use Aurora\Core\Storage\Adapter\LocalStorageAdapter;
use Aurora\Core\Storage\Command\ExportStorageCommand;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\StorageManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;

/**
 * What the export promises: the same keys at the same paths, nothing fetched
 * twice, and a dry run that writes nothing. Run against the local disk, which
 * speaks the same adapter contract as R2 without a bucket.
 */
final class ExportStorageCommandTest extends TestCase
{
    private string $workDir;

    private LocalStorageAdapter $source;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/aurora-export-'.uniqid();
        (new Filesystem())->mkdir($this->workDir.'/source');
        $this->source = new LocalStorageAdapter(new Filesystem(), $this->workDir.'/source');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
    }

    public function testEveryKeyLandsAtTheSamePath(): void
    {
        $this->source->write('ged/2026/09/a.png', 'aaa');
        $this->source->write('contracts/b.pdf', 'bb');

        $tester = $this->export(['target' => $this->workDir.'/copy', '--disk' => 'local']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('aaa', file_get_contents($this->workDir.'/copy/ged/2026/09/a.png'));
        self::assertSame('bb', file_get_contents($this->workDir.'/copy/contracts/b.pdf'));
        self::assertStringContainsString('2 copied', $tester->getDisplay());
    }

    public function testASecondRunOnlyFetchesWhatChanged(): void
    {
        $this->source->write('ged/a.png', 'aaa');
        $this->export(['target' => $this->workDir.'/copy', '--disk' => 'local']);

        $this->source->write('ged/b.png', 'b');
        file_put_contents($this->workDir.'/copy/ged/a.png', 'stale, and longer');

        $display = $this->export(['target' => $this->workDir.'/copy', '--disk' => 'local'])->getDisplay();

        self::assertStringContainsString('2 copied', $display);
        self::assertSame('aaa', file_get_contents($this->workDir.'/copy/ged/a.png'));
        self::assertStringContainsString('0 already', $display);

        self::assertStringContainsString('2 already', $this->export(['target' => $this->workDir.'/copy', '--disk' => 'local'])->getDisplay());
    }

    public function testThePrefixNarrowsTheCopy(): void
    {
        $this->source->write('ged/a.png', 'a');
        $this->source->write('contracts/b.pdf', 'b');

        $this->export(['target' => $this->workDir.'/copy', '--disk' => 'local', '--prefix' => 'ged']);

        self::assertTrue(is_file($this->workDir.'/copy/ged/a.png'));
        self::assertFalse(is_file($this->workDir.'/copy/contracts/b.pdf'));
    }

    public function testADryRunWeighsWithoutWriting(): void
    {
        $this->source->write('ged/a.png', 'aaa');

        $tester = $this->export(['target' => $this->workDir.'/copy', '--disk' => 'local', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ged', $tester->getDisplay());
        self::assertFalse(is_file($this->workDir.'/copy/ged/a.png'));
    }

    /** A file rewritten at the same length is still fetched again. */
    public function testASameSizeRewriteIsFetchedAgain(): void
    {
        $this->source->write('ged/a.png', 'aaa');
        $this->export(['target' => $this->workDir.'/copy', '--disk' => 'local']);

        // The copy is older than the source now, and just as long.
        touch($this->workDir.'/copy/ged/a.png', time() - 3600);
        file_put_contents($this->workDir.'/copy/ged/a.png', 'zzz');
        touch($this->workDir.'/copy/ged/a.png', time() - 3600);

        $this->export(['target' => $this->workDir.'/copy', '--disk' => 'local']);

        self::assertSame('aaa', file_get_contents($this->workDir.'/copy/ged/a.png'));
    }

    /** `/` trims to nothing: every key would pass the guard and land at the root. */
    public function testTheRootDirectoryIsRefusedAsATarget(): void
    {
        $this->source->write('ged/a.png', 'aaa');

        self::assertSame(Command::INVALID, $this->export(['target' => '/', '--disk' => 'local'])->getStatusCode());
    }

    public function testAnUnknownDiskIsRefused(): void
    {
        self::assertSame(Command::INVALID, $this->export(['target' => $this->workDir.'/copy', '--disk' => 'floppy'])->getStatusCode());
    }

    /** @param array<string, mixed> $input */
    private function export(array $input): CommandTester
    {
        $provider = new class implements ActiveStorageDiskProviderInterface {
            public function activeDisk(): StorageDiskEnum
            {
                return StorageDiskEnum::Local;
            }
        };

        $tester = new CommandTester(new ExportStorageCommand(new StorageManager([$this->source], $provider)));
        $tester->execute($input);

        return $tester;
    }
}
