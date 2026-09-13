<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\DocumentCategory\Manager\DocumentCategoryManagerInterface;
use Aurora\Module\Ged\DocumentCategory\Message\PurgeTrashedCategoriesMessage;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Empties the category trash of what has been in it long enough.
 *
 * Reads the same `TrashAutoPurgeDays` setting as every other purge. A category
 * left behind would be a trash that says "thirty days" and keeps its rows
 * forever, which is the kind of half-promise the overview screen now counts
 * down in front of the reader.
 */
#[AsMessageHandler]
final readonly class PurgeTrashedCategoriesHandler
{
    public function __construct(
        private DocumentCategoryManagerInterface $categoryManager,
        private SettingRepository $settingRepository,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeTrashedCategoriesMessage $message): void
    {
        $days = (int) $this->settingRepository->get(
            ApplicationParameterEnum::TrashAutoPurgeDays->value,
            ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue(),
        );

        if ($days <= 0) {
            return;
        }

        $purged = $this->categoryManager->purgeTrashedBefore(new DateTimeImmutable(sprintf('-%d days', $days)));

        if (0 === $purged) {
            return;
        }

        $this->logger->info('Purged {count} trashed category(ies) older than {days} days.', [
            'count' => $purged,
            'days' => $days,
        ]);
    }
}
