<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\MessageHandler;

use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Studio\Contract\Message\NotifyEffectiveTerminationsMessage;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Service\ContractTeamNotifier;
use Aurora\Module\Studio\StudioContext;
use DateTimeImmutable;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The morning a termination bites.
 *
 * A termination is recorded weeks ahead, with its date, and nothing happened
 * on that date: the contract silently moved to « Terminés » and the team of
 * the customer's spaces found out when the work went on without one. The bell
 * now says so on the day, in the site's calendar.
 */
#[AsMessageHandler]
final readonly class NotifyEffectiveTerminationsHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ContractTeamNotifier $teamNotifier,
        private StudioContext $studioContext,
        private SiteTimezone $siteTimezone,
    ) {}

    public function __invoke(NotifyEffectiveTerminationsMessage $message): void
    {
        if (!$this->studioContext->areContractsEnabled()) {
            return;
        }

        $today = new DateTimeImmutable('today', $this->siteTimezone->get());

        foreach ($this->contractRepository->findTerminationsEffectiveOn($today) as $contract) {
            $this->teamNotifier->terminationEffective($contract);
        }
    }
}
