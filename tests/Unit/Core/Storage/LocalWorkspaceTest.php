<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage;

use Aurora\Core\Storage\Adapter\LocalStorageAdapter;
use Aurora\Core\Storage\Exception\StorageException;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The piece that lets GD and `pdftoppm` keep taking filenames.
 *
 * Each behaviour is asserted twice, once against the local disk and once
 * against a backend with no paths at all, because the entire value of this
 * class is that those two produce the same outcome. The local assertions also
 * pin the no-copy fast path: the day it starts making temporaries, image
 * processing gets slower for no reason and nothing else would notice.
 */
final class LocalWorkspaceTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;
    private LocalStorageAdapter $local;
    private InMemoryStorageAdapter $remote;
    private LocalWorkspace $workspace;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir().'/aurora_workspace_test_'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->root);
        $this->local = new LocalStorageAdapter($this->filesystem, $this->root);
        $this->remote = new InMemoryStorageAdapter();
        $this->workspace = new LocalWorkspace($this->filesystem);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
        self::assertSame([], glob(sys_get_temp_dir().'/'.LocalWorkspace::TMP_PREFIX.'*') ?: [], 'no temporary left behind');
    }

    public function testReadableHandsOverTheBytesOnALocalDisk(): void
    {
        $this->local->write('ged/a.txt', 'contenu');

        $seen = $this->workspace->readable($this->local, 'ged/a.txt', static fn (string $path): string => (string) file_get_contents($path));

        self::assertSame('contenu', $seen);
    }

    public function testReadableHandsOverTheBytesOnARemoteBackend(): void
    {
        $this->remote->write('ged/a.txt', 'contenu');

        $seen = $this->workspace->readable($this->remote, 'ged/a.txt', static fn (string $path): string => (string) file_get_contents($path));

        self::assertSame('contenu', $seen);
        self::assertSame(['ged/a.txt'], $this->remote->reads);
    }

    public function testReadableGivesTheStoredFileItselfOnALocalDisk(): void
    {
        $this->local->write('ged/a.txt', 'contenu');

        $path = $this->workspace->readable($this->local, 'ged/a.txt', static fn (string $path): string => $path);

        self::assertSame($this->local->localPath('ged/a.txt'), $path, 'the local path is lent, not copied');
    }

    public function testReadableStoresNothingBackFromARemoteBackend(): void
    {
        $this->remote->write('ged/a.txt', 'original');
        $this->remote->writes = [];

        $this->workspace->readable($this->remote, 'ged/a.txt', static function (string $path): void {
            file_put_contents($path, 'bricolage');
        });

        self::assertSame('original', $this->remote->read('ged/a.txt'));
        self::assertSame([], $this->remote->writes, 'a read never writes');
    }

    public function testWritableKeepsChangesOnALocalDisk(): void
    {
        $this->local->write('ged/a.txt', 'avant');

        $this->workspace->writable($this->local, 'ged/a.txt', static function (string $path): void {
            file_put_contents($path, 'apres');
        });

        self::assertSame('apres', $this->local->read('ged/a.txt'));
    }

    public function testWritableKeepsChangesOnARemoteBackend(): void
    {
        $this->remote->write('ged/a.txt', 'avant');

        $this->workspace->writable($this->remote, 'ged/a.txt', static function (string $path): void {
            file_put_contents($path, 'apres');
        });

        self::assertSame('apres', $this->remote->read('ged/a.txt'));
    }

    /**
     * Re-encoding that dies half way must not replace a good file with half of
     * one. `ImageRenditionGenerator` rewrites JPEG sources in place, so this is
     * the exact shape of the risk.
     */
    public function testWritableStoresNothingWhenTheWorkThrows(): void
    {
        $this->remote->write('ged/a.txt', 'intact');
        $this->remote->writes = [];

        try {
            $this->workspace->writable($this->remote, 'ged/a.txt', static function (string $path): void {
                file_put_contents($path, 'tronque');
                throw new RuntimeException('encoding blew up');
            });
            self::fail('the exception should reach the caller');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame('intact', $this->remote->read('ged/a.txt'));
        self::assertSame([], $this->remote->writes);
    }

    public function testWritableOnAMissingKeyThrows(): void
    {
        $this->expectException(StorageException::class);

        $this->workspace->writable($this->remote, 'ged/nowhere.txt', static fn (string $path): string => $path);
    }

    public function testTargetStoresWhatTheWorkCreatedOnALocalDisk(): void
    {
        $this->workspace->target($this->local, 'ged/2026/09/new.txt', static function (string $path): void {
            file_put_contents($path, 'produit');
        });

        self::assertSame('produit', $this->local->read('ged/2026/09/new.txt'));
    }

    public function testTargetStoresWhatTheWorkCreatedOnARemoteBackend(): void
    {
        $this->workspace->target($this->remote, 'ged/2026/09/new.txt', static function (string $path): void {
            file_put_contents($path, 'produit');
        });

        self::assertSame('produit', $this->remote->read('ged/2026/09/new.txt'));
    }

    public function testTargetCreatesTheParentDirectoryOnALocalDisk(): void
    {
        $written = $this->workspace->target($this->local, 'ged/deep/deeper/new.txt', static function (string $path): bool {
            return false !== file_put_contents($path, 'produit');
        });

        self::assertTrue($written, 'the work can write without creating directories itself');
    }

    /**
     * A PDF with no renderable first page, a crop that turned out impossible:
     * the work legitimately produces nothing, and an empty object at the key
     * would be worse than no object.
     */
    public function testTargetStoresNothingWhenTheWorkWroteNothing(): void
    {
        $this->workspace->target($this->remote, 'ged/thumb.png', static function (string $path): void {
            // deliberately produces no output
        });

        self::assertFalse($this->remote->exists('ged/thumb.png'));
        self::assertSame([], $this->remote->writes);
    }

    public function testTargetPassesAPathCarryingTheKeyExtension(): void
    {
        $extension = $this->workspace->target($this->remote, 'ged/thumb.png', static fn (string $path): string => pathinfo($path, PATHINFO_EXTENSION));

        self::assertSame('png', $extension, 'GD picks its encoder from the filename');
    }

    public function testTemporariesAreRemovedEvenWhenTheWorkThrows(): void
    {
        $this->remote->write('ged/a.txt', 'x');

        try {
            $this->workspace->readable($this->remote, 'ged/a.txt', static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // the tearDown assertion is the real check
        }

        self::assertTrue(true);
    }
}
