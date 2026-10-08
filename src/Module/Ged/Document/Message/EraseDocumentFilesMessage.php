<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Message;

use Aurora\Core\Storage\Enum\StorageDiskEnum;

/**
 * Erase the bytes of documents deleted for good, out of the request.
 *
 * Carries the paths rather than ids: the rows are gone by the time a worker
 * reads this, and the version rows went with them through an
 * `ON DELETE CASCADE`, so nothing would be left to read the paths from.
 */
final readonly class EraseDocumentFilesMessage
{
    /**
     * @param list<string> $renditions
     * @param list<string> $paths
     */
    public function __construct(
        public StorageDiskEnum $disk,
        public array $renditions,
        public array $paths,
    ) {}
}
