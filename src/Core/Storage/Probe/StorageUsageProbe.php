<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Probe;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Doctrine\DBAL\Connection;

/**
 * How much the storage weighs, and on which side.
 *
 * **Read from the documents table, not from the disk.** What matters to the
 * person looking is what the application has stored somewhere and answers
 * for. A `du` would also count the leftovers of a failed import and the
 * generated sizes that a purge has not collected yet, which gives a bigger
 * and less true number.
 *
 * An aggregate query rather than a load: the inventory can run to thousands
 * of rows, and nobody needs to see them to know their sum.
 */
final readonly class StorageUsageProbe
{
    public function __construct(private Connection $connection) {}

    /**
     * The weight and the count, per location.
     *
     * Both locations are always returned, even at zero: a screen that hid the
     * empty one would not let you read "nothing is left on the server", which
     * is exactly what you came to check.
     *
     * @return array<string, array{count: int, bytes: int}>
     */
    public function byDisk(): array
    {
        $usage = [];

        foreach (StorageDiskEnum::cases() as $disk) {
            $usage[$disk->value] = ['count' => 0, 'bytes' => 0];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT storage_disk, COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes
             FROM core_ged_documents
             WHERE deleted_at IS NULL
             GROUP BY storage_disk',
        );

        foreach ($rows as $row) {
            $disk = (string) $row['storage_disk'];

            if (!isset($usage[$disk])) {
                continue;
            }

            $usage[$disk] = [
                'count' => (int) $row['files'],
                'bytes' => (int) $row['bytes'],
            ];
        }

        return $usage;
    }

    /**
     * The weight of the files stored in the folder of each client space.
     *
     * **The folder, not the attachments.** What we want to know is what a
     * space caused to be *uploaded*: a document picked from the media library
     * was already there and would have stayed without it. The uploader stores
     * everything that comes in through a space in its folder, so the folder is
     * exactly the answer.
     *
     * A single grouped query: a list of spaces counts dozens of them, and one
     * sum per row would make as many round trips as rows.
     *
     * @return array<int, int> space id => bytes
     */
    public function bySpace(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id AS space_id, COALESCE(SUM(d.size), 0) AS bytes
             FROM core_studio_customer_spaces s
             JOIN core_ged_documents d ON d.folder_id = s.document_folder_id AND d.deleted_at IS NULL
             WHERE s.document_folder_id IS NOT NULL
             GROUP BY s.id',
        );

        $bytes = [];

        foreach ($rows as $row) {
            $bytes[(int) $row['space_id']] = (int) $row['bytes'];
        }

        return $bytes;
    }
}
