<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\MessageHandler;

use Aurora\Core\Storage\Message\DeleteStoredFilesMessage;
use Aurora\Core\Storage\StorageManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes stored files a request had no time to wait for.
 *
 * A disk nobody configured is skipped rather than asked: it holds nothing, and
 * asking would fail on every attempt and send the message to the failure
 * transport for no file at all. Replayable as it stands, since deleting a file
 * that is already gone is not an error on either disk.
 */
#[AsMessageHandler]
final readonly class DeleteStoredFilesHandler
{
    public function __construct(
        private StorageManager $storageManager,
    ) {}

    public function __invoke(DeleteStoredFilesMessage $message): void
    {
        if ([] === $message->keys) {
            return;
        }

        foreach ($this->storageManager->all() as $adapter) {
            if ($adapter->isReady()) {
                $adapter->deleteMany($message->keys);
            }
        }
    }
}
