<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Folder\Manager\NoteFolderManagerInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Message\PurgeTrashedNotesMessage;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Empties the notes trash of what has been in it long enough, folders
 * included.
 *
 * Reads the same `TrashAutoPurgeDays` setting as the other trashes: a
 * retention window is a promise made to whoever deleted something, and three
 * different windows would be three promises nobody announced.
 *
 * Goes through the manager so the images a note referenced leave with it,
 * which is the same code the trash's own button runs.
 */
#[AsMessageHandler]
final readonly class PurgeTrashedNotesHandler
{
    public function __construct(
        private MarkdownNoteManagerInterface $noteManager,
        private NoteFolderManagerInterface $folderManager,
        private SettingRepository $settingRepository,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeTrashedNotesMessage $message): void
    {
        $days = (int) $this->settingRepository->get(
            ApplicationParameterEnum::TrashAutoPurgeDays->value,
            ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue(),
        );

        if ($days <= 0) {
            return;
        }

        $cutoff = new DateTimeImmutable(sprintf('-%d days', $days));

        // The notes go first. A folder destroyed before them would clear the
        // `folder_id` its notes carry, and they would come back from the
        // trash at the root instead of leaving with their folder.
        $purged = $this->noteManager->purgeTrashedBefore($cutoff);
        $purgedFolders = $this->folderManager->purgeTrashedBefore($cutoff);

        if (0 === $purged && 0 === $purgedFolders) {
            return;
        }

        $this->logger->info('Purged {count} trashed note(s) and {folders} folder(s) older than {days} days.', [
            'count' => $purged,
            'folders' => $purgedFolders,
            'days' => $days,
        ]);
    }
}
