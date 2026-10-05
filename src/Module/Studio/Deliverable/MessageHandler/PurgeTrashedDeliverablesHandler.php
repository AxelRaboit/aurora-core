<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\Deliverable\Manager\DeliverableManager;
use Aurora\Module\Studio\Deliverable\Message\PurgeTrashedDeliverablesMessage;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

/**
 * Empties the deliverables' trash of what has been in it long enough.
 *
 * Reads the same `TrashAutoPurgeDays` setting as the other trashes: a
 * retention window is a promise made to whoever deleted something, and one
 * window per module would be as many promises nobody announced. Goes through
 * the manager, so each deliverable is logged as destroyed - the line that says
 * who deleted what, long after the fact - and its reading links go with it.
 */
#[AsMessageHandler]
final readonly class PurgeTrashedDeliverablesHandler
{
    public function __construct(
        private DeliverableManager $manager,
        private SettingRepository $settingRepository,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeTrashedDeliverablesMessage $message): void
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

        $this->logger->info('Purged {count} trashed deliverable(s) older than {days} days.', ['count' => $purged, 'days' => $days]);
    }
}
