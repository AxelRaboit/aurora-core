<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\MessageHandler;

use Aurora\Module\Ged\Document\Message\EraseDocumentFilesMessage;
use Aurora\Module\Ged\Document\Service\DocumentFileEraser;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Erases what a trash emptied in the request left on disk.
 *
 * Replayable as it stands: deleting a file that is already gone is not an
 * error on either disk, so a retry after a half-done batch simply finishes it.
 */
#[AsMessageHandler]
final readonly class EraseDocumentFilesHandler
{
    public function __construct(
        private DocumentFileEraser $eraser,
    ) {}

    public function __invoke(EraseDocumentFilesMessage $message): void
    {
        $this->eraser->erase($message->disk, $message->renditions, $message->paths);
    }
}
