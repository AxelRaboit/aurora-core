<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Service\ImageRenditionGenerator;
use Aurora\Core\Storage\StorageManager;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;

/**
 * Erases the bytes of documents whose rows are already gone.
 *
 * The renditions go unconditionally: they belong to one document and nobody
 * else names them. The original files only go when no surviving row points at
 * them, because `recordVersion()` makes a version row share the live
 * document's `filePath`, and a copy can share its file with its source.
 *
 * Shared by the worker and by a manager built without a bus, so the two can
 * never erase differently. Run after the flush, never before: the rows being
 * deleted must already be gone for the reference check to answer about
 * survivors only, and a failed flush must not cost anyone their bytes.
 */
final readonly class DocumentFileEraser
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentVersionRepository $versionRepository,
        private ImageRenditionGenerator $renditionGenerator,
        private StorageManager $storageManager,
    ) {}

    /**
     * @param array<array-key, string> $renditions
     * @param list<string>             $paths
     */
    public function erase(StorageDiskEnum $disk, array $renditions, array $paths): void
    {
        $adapter = $this->storageManager->forDisk($disk);

        $this->renditionGenerator->deleteRenditions($adapter, $renditions);

        if ([] === $paths) {
            return;
        }

        // Asked again here rather than trusted from the request: by the time
        // a worker runs this, a row may have been created that points at one
        // of these paths. Not scoped to the disk, on purpose: a path in use
        // on the other one keeps the file, and an orphan costs less than a
        // blank document.
        $stillInUse = array_merge(
            $this->documentRepository->filterPathsInUse($paths),
            $this->versionRepository->filterPathsInUse($paths),
        );

        $unreferenced = array_values(array_diff($paths, $stillInUse));

        if ([] !== $unreferenced) {
            $adapter->deleteMany($unreferenced);
        }
    }
}
