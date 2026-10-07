<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManagerInterface;
use Aurora\Module\Studio\SpaceContent\Message\PurgeTrashedSpaceContentsMessage;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

/**
 * Empties the contents' trash of what has been in it long enough.
 *
 * Reads the same `TrashAutoPurgeDays` setting as the other trashes, and goes
 * through the manager, so each content item is logged as destroyed. Its thread
 * and attachments go by cascade; the documents stay in the media library.
 */
#[AsMessageHandler]
final readonly class PurgeTrashedSpaceContentsHandler
{
    public function __construct(
        private SpaceContentItemManagerInterface $manager,
        private SettingRepository $settingRepository,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeTrashedSpaceContentsMessage $message): void
    {
        $days = (int) $this->settingRepository->get(
            ApplicationParameterEnum::TrashAutoPurgeDays->value,
            ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue(),
        );

        if ($days <= 0) {
            return;
        }

        $purged = $this->manager->purgeTrashedBefore(new DateTimeImmutable(sprintf('-%d days', $days)));

        if (0 === $purged) {
            return;
        }

        $this->logger->info('Purged {count} trashed space content item(s) older than {days} days.', ['count' => $purged, 'days' => $days]);
    }
}
