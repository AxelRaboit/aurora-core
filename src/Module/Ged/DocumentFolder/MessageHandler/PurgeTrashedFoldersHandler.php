<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentFolder\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\DocumentFolder\Manager\DocumentFolderManagerInterface;
use Aurora\Module\Ged\DocumentFolder\Message\PurgeTrashedFoldersMessage;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Empties the folder trash of what has been in it long enough.
 *
 * Reads the same `TrashAutoPurgeDays` setting as every other purge. A folder
 * left behind would be a trash that says "thirty days" and keeps its rows
 * forever, which is the kind of half-promise the overview screen now counts
 * down in front of the reader.
 */
#[AsMessageHandler]
final readonly class PurgeTrashedFoldersHandler
{
    public function __construct(
        private DocumentFolderManagerInterface $folderManager,
        private SettingRepository $settingRepository,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeTrashedFoldersMessage $message): void
    {
        $days = (int) $this->settingRepository->get(
            ApplicationParameterEnum::TrashAutoPurgeDays->value,
            ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue(),
        );

        if ($days <= 0) {
            return;
        }

        $purged = $this->folderManager->purgeTrashedBefore(new DateTimeImmutable(sprintf('-%d days', $days)));

        if (0 === $purged) {
            return;
        }

        $this->logger->info('Purged {count} trashed folder(s) older than {days} days.', [
            'count' => $purged,
            'days' => $days,
        ]);
    }
}
