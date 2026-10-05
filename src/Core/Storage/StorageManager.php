<?php

declare(strict_types=1);

namespace Aurora\Core\Storage;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Exception\StorageException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function sprintf;

/**
 * Answers the two questions callers actually have about storage.
 *
 * *Where do I write?* - `active()`, the disk this installation writes new
 * files to. *Who owns these bytes?* - `forDisk()`, the adapter for a file that
 * was written some time ago, possibly on a different suite. Keeping them
 * apart is what will later let one document sit on object storage while its
 * neighbour is still on the server's disk, without either of them knowing.
 *
 * Adapters arrive through the `aurora.storage_adapter` tag, so a backend is
 * added by writing a class, not by editing this one.
 */
final class StorageManager
{
    /** @var array<string, StorageAdapterInterface>|null */
    private ?array $byDisk = null;

    /**
     * @param iterable<StorageAdapterInterface> $adapters
     */
    public function __construct(
        #[AutowireIterator('aurora.storage_adapter')]
        private readonly iterable $adapters,
        private readonly ActiveStorageDiskProviderInterface $activeDiskProvider,
    ) {}

    /**
     * The disk new files go to, as an administrator chose it.
     *
     * Callers ask this rather than naming a disk, which is what let the choice
     * move from a hardcoded constant to a setting without any of them
     * changing.
     */
    public function active(): StorageAdapterInterface
    {
        return $this->forDisk($this->activeDiskProvider->activeDisk());
    }

    /**
     * The active disk itself, for callers that have to record it rather than
     * write through it - stamping a document with where its bytes just went.
     */
    public function activeDisk(): StorageDiskEnum
    {
        return $this->activeDiskProvider->activeDisk();
    }

    /**
     * Every registered backend, in no particular order.
     *
     * For the callers that have to ask all of them rather than one: a file
     * written some time ago is on whichever disk held it then, and the path
     * alone does not say which.
     *
     * @return list<StorageAdapterInterface>
     */
    public function all(): array
    {
        return array_values($this->index());
    }

    public function forDisk(StorageDiskEnum $disk): StorageAdapterInterface
    {
        $adapter = $this->index()[$disk->value] ?? null;

        if (!$adapter instanceof StorageAdapterInterface) {
            throw new StorageException(sprintf('No storage adapter registered for disk "%s".', $disk->value));
        }

        return $adapter;
    }

    /** @return array<string, StorageAdapterInterface> */
    private function index(): array
    {
        if (null !== $this->byDisk) {
            return $this->byDisk;
        }

        $index = [];
        foreach ($this->adapters as $adapter) {
            $index[$adapter->disk()->value] = $adapter;
        }

        return $this->byDisk = $index;
    }
}
