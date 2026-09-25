<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage;

use Aurora\Core\Storage\ActiveStorageDiskProviderInterface;
use Aurora\Core\Storage\Adapter\LocalStorageAdapter;
use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\StoredDiskHintInterface;
use Aurora\Core\Storage\StoredFileLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Which backend answers for a `/uploads/{path}` request.
 *
 * The case that matters is the one that was missing: a document moved to a
 * remote backend while new files still go to the server's disk. That
 * combination is offered by the product on purpose - the relocation button
 * exists for it - and it used to serve 404 for every moved file, because the
 * locator stopped looking once it saw the active disk was the local one.
 *
 * Found in production on 12/09/2026, on four films and their posters that had
 * been moved deliberately and vanished from a public page.
 */
final class StoredFileLocatorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/aurora-locator-'.uniqid();
        mkdir($this->workDir.'/local', 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->workDir);
    }

    public function testAFilePresentLocallyIsServedLocally(): void
    {
        $local = $this->local();
        $local->write('photo.jpg', 'bytes');

        $locator = $this->locator([$local, $this->remote()], StorageDiskEnum::Local);

        self::assertSame($local, $locator->locate('photo.jpg'));
    }

    /**
     * The regression. New files go to the server, one document was moved to
     * the bucket, and its address has to keep working.
     */
    public function testAFileMovedToTheRemoteBackendIsFoundWhileTheActiveDiskIsLocal(): void
    {
        $remote = $this->remote();
        $remote->objects['reel.mp4'] = 'bytes';

        $locator = $this->locator([$this->local(), $remote], StorageDiskEnum::Local);

        self::assertSame($remote, $locator->locate('reel.mp4'));
    }

    public function testAFileNobodyHoldsIsFoundNowhere(): void
    {
        $locator = $this->locator([$this->local(), $this->remote()], StorageDiskEnum::Local);

        self::assertNull($locator->locate('gone.jpg'));
    }

    /**
     * The local check is a syscall; the remote one is a billed request. A file
     * sitting on the server must not cost one, which is the reason the local
     * disk is asked first rather than last.
     */
    public function testALocalHitAsksTheRemoteBackendNothing(): void
    {
        $local = $this->local();
        $local->write('photo.jpg', 'bytes');
        $remote = $this->remote();

        $this->locator([$local, $remote], StorageDiskEnum::Local)->locate('photo.jpg');

        self::assertSame([], $remote->existsCalls);
    }

    /**
     * A backend nobody configured has no address to ask and throws when
     * called. Sweeping the adapters must step over it rather than catch it,
     * because catching would hide the failures of a backend that *is*
     * configured.
     */
    public function testAnUnconfiguredBackendIsNotAsked(): void
    {
        $remote = $this->remote();
        $remote->ready = false;
        $remote->objects['reel.mp4'] = 'bytes';

        $locator = $this->locator([$this->local(), $remote], StorageDiskEnum::Local);

        self::assertNull($locator->locate('reel.mp4'));
        self::assertSame([], $remote->existsCalls);
    }

    /**
     * The cost this exists to remove: a module that recorded the disk spares
     * the remote existence check, a network round trip on every request.
     * Measured on 2026-09-25 as most of 0.3 seconds per picture.
     */
    public function testAHintedRemoteFileIsServedWithoutAskingTheBackend(): void
    {
        $remote = $this->remote();
        $remote->objects['ged/photo.webp'] = 'bytes';

        $locator = $this->locator([$this->local(), $remote], StorageDiskEnum::R2, [$this->hint(StorageDiskEnum::R2)]);

        self::assertSame($remote, $locator->locate('ged/photo.webp'));
        self::assertSame([], $remote->existsCalls);
    }

    /**
     * A hint only answers for the keys it claims; the rest are still looked
     * for, which keeps every other area working exactly as before.
     */
    public function testAKeyNoHintClaimsIsStillLookedFor(): void
    {
        $remote = $this->remote();
        $remote->objects['notes/image.webp'] = 'bytes';

        $locator = $this->locator([$this->local(), $remote], StorageDiskEnum::R2, [$this->hint(StorageDiskEnum::R2)]);

        self::assertSame($remote, $locator->locate('notes/image.webp'));
        self::assertSame(['notes/image.webp'], $remote->existsCalls);
    }

    /**
     * No document owns the path, or its disk is not ready: fall back to
     * looking rather than answering with a backend that cannot serve it.
     */
    public function testAnUnknownOrUnreadyHintFallsBackToLooking(): void
    {
        $remote = $this->remote();
        $remote->objects['ged/orphan.webp'] = 'bytes';

        $unknown = $this->locator([$this->local(), $remote], StorageDiskEnum::R2, [$this->hint(null)]);
        self::assertSame($remote, $unknown->locate('ged/orphan.webp'));

        $remote->ready = false;
        $unready = $this->locator([$this->local(), $remote], StorageDiskEnum::R2, [$this->hint(StorageDiskEnum::R2)]);
        self::assertNull($unready->locate('ged/orphan.webp'));
    }

    private function hint(?StorageDiskEnum $disk): StoredDiskHintInterface
    {
        return new class($disk) implements StoredDiskHintInterface {
            public function __construct(private readonly ?StorageDiskEnum $disk) {}

            public function supports(string $key): bool
            {
                return str_starts_with($key, 'ged/');
            }

            public function diskFor(string $key): ?StorageDiskEnum
            {
                return $this->disk;
            }
        };
    }

    private function local(): LocalStorageAdapter
    {
        return new LocalStorageAdapter(new Filesystem(), $this->workDir.'/local');
    }

    private function remote(): InMemoryStorageAdapter
    {
        return new InMemoryStorageAdapter(StorageDiskEnum::R2);
    }

    /**
     * @param list<StorageAdapterInterface> $adapters
     * @param list<StoredDiskHintInterface> $hints
     */
    private function locator(array $adapters, StorageDiskEnum $active, array $hints = []): StoredFileLocator
    {
        $provider = new class($active) implements ActiveStorageDiskProviderInterface {
            public function __construct(private readonly StorageDiskEnum $disk) {}

            public function activeDisk(): StorageDiskEnum
            {
                return $this->disk;
            }
        };

        return new StoredFileLocator(new StorageManager($adapters, $provider), $hints);
    }
}
