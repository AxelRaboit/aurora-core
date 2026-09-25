<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Storage;

use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\StoredDiskHintInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;

use function str_starts_with;

/**
 * The disk a media library file lives on, read from its document.
 *
 * A document is written to one disk and its `storageDisk` says which, variants
 * included: they are generated next to their source and move with it. One
 * query on a few hundred rows is far cheaper than asking a remote bucket
 * whether the object exists.
 */
final readonly class GedStoredDiskHint implements StoredDiskHintInterface
{
    public function __construct(
        private DocumentRepository $documentRepository,
    ) {}

    public function supports(string $key): bool
    {
        return str_starts_with($key, StorageAreaEnum::Ged->value.'/');
    }

    public function diskFor(string $key): ?StorageDiskEnum
    {
        return $this->documentRepository->findStorageDiskForPath($key);
    }
}
