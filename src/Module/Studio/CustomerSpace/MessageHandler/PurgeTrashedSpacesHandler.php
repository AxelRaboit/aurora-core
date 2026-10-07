<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\CustomerSpace\Manager\CustomerSpaceManagerInterface;
use Aurora\Module\Studio\CustomerSpace\Message\PurgeTrashedSpacesMessage;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

/**
 * Empties the client spaces' trash of what has been in it long enough.
 *
 * Reads the same `TrashAutoPurgeDays` setting as the other trashes, and goes
 * through the manager: each space is logged as destroyed, its dates leave the
 * calendar and its note space is released, exactly as the trash's own button
 * does it.
 */
#[AsMessageHandler]
final readonly class PurgeTrashedSpacesHandler
{
    public function __construct(
        private CustomerSpaceManagerInterface $manager,
        private SettingRepository $settingRepository,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeTrashedSpacesMessage $message): void
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

        $this->logger->info('Purged {count} trashed client space(s) older than {days} days.', ['count' => $purged, 'days' => $days]);
    }
}
