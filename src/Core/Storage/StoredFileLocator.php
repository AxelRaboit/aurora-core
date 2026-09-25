<?php

declare(strict_types=1);

namespace Aurora\Core\Storage;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Exception\StorageException;
use Aurora\Core\Storage\Workspace\LocalPathAware;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Which backend holds the bytes behind a `/uploads/{path}` request.
 *
 * The serving endpoint receives a path and nothing else. It could look the
 * path up in the database to find the document that owns it, but that would be
 * a query per image on every page, and the answer is cheaper than that: ask
 * the local disk, whose check is a syscall.
 *
 * So local first, then whatever else is configured. A file present locally is
 * served locally, which is both correct and free, and that is the overwhelming
 * majority of requests. A file that is not is on another backend, and only
 * then is a remote question asked.
 *
 * **It used to stop at the active disk, and that was a data-loss-shaped bug.**
 * An administrator is explicitly allowed to configure a bucket, leave new
 * files on the server, and move a document across by hand - the relocation
 * button exists for exactly that. The moved document then lived on a backend
 * the locator would not consult, because the active disk was still the local
 * one, and every one of its URLs answered 404. Seen in production on
 * 12/09/2026: four films and their posters, moved on purpose, gone from a
 * public page. The bytes were never at risk; nothing went looking for them.
 *
 * The one moment a path exists on both sides is while a document is being
 * moved. Local wins then, and serves the same bytes the other side holds, so
 * nobody notices.
 */
final readonly class StoredFileLocator
{
    /**
     * @param iterable<StoredDiskHintInterface> $hints
     */
    public function __construct(
        private StorageManager $storageManager,
        #[AutowireIterator('aurora.stored_disk_hint')]
        private iterable $hints = [],
    ) {}

    /**
     * The adapter that can serve `$key`, or null when nothing holds it.
     */
    public function locate(string $key): ?StorageAdapterInterface
    {
        $local = $this->storageManager->forDisk(StorageDiskEnum::Local);

        // Deliberately not `exists()`: on a local adapter that is the syscall
        // we want, and this branch must not become a billed request the day
        // some other backend implements LocalPathAware.
        if ($local instanceof LocalPathAware && is_file($local->localPath($key))) {
            return $local;
        }

        // A module that recorded where it put the file saves the existence
        // check below, which on a remote disk is a network round trip.
        $hinted = $this->hinted($key);

        if ($hinted instanceof StorageAdapterInterface) {
            return $hinted;
        }

        foreach ($this->storageManager->all() as $adapter) {
            // Already asked, and it answered no.
            if ($adapter === $local) {
                continue;
            }

            // Skipped rather than asked: a backend nobody configured has no
            // address to ask and throws when called. Catching that would be
            // wrong, not merely ugly - an exception is how a backend that *is*
            // configured reports a real failure, and the two must not read the
            // same.
            if (!$adapter->isReady()) {
                continue;
            }

            if ($adapter->exists($key)) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * The remote disk a hint names for this key, if it is ready. Trusted
     * without asking the disk: that is the whole point. A local answer is
     * not taken here, since the local disk was already checked for real.
     */
    private function hinted(string $key): ?StorageAdapterInterface
    {
        foreach ($this->hints as $hint) {
            if (!$hint->supports($key)) {
                continue;
            }

            $disk = $hint->diskFor($key);

            if (null === $disk || StorageDiskEnum::Local === $disk) {
                return null;
            }

            try {
                $adapter = $this->storageManager->forDisk($disk);
            } catch (StorageException) {
                return null;
            }

            return $adapter->isReady() ? $adapter : null;
        }

        return null;
    }
}
