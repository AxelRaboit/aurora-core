<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Message\PurgeTrashedDecksMessage;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

/**
 * Destroys the decks that have been in the trash long enough.
 *
 * Reads the same `TrashAutoPurgeDays` setting as every other trash: a
 * retention window is a promise made to whoever deleted something, and one
 * window per module would be as many promises nobody announced. Goes through
 * the manager, so a deck's slides and share links go with it.
 */
#[AsMessageHandler]
final readonly class PurgeTrashedDecksHandler
{
    public function __construct(
        private DeckRepository $decks,
        private DeckManager $manager,
        private SettingRepository $settingRepository,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeTrashedDecksMessage $message): void
    {
        $days = (int) $this->settingRepository->get(
            ApplicationParameterEnum::TrashAutoPurgeDays->value,
            ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue(),
        );
        if ($days <= 0) {
            return;
        }

        $purged = 0;
        foreach ($this->decks->findTrashedBefore(new DateTimeImmutable(sprintf('-%d days', $days))) as $deck) {
            $this->manager->forceDelete($deck);
            ++$purged;
        }

        if (0 === $purged) {
            return;
        }

        $this->logger->info('Purged {count} trashed deck(s) older than {days} days.', ['count' => $purged, 'days' => $days]);
    }
}
