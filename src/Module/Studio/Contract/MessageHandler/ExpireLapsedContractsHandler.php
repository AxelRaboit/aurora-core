<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\MessageHandler;

use Aurora\Module\Studio\Contract\Access\Manager\ContractAccessLinkManagerInterface;
use Aurora\Module\Studio\Contract\Message\ExpireLapsedContractsMessage;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Says « Expiré » when the thirty days are up.
 *
 * Unlike the reminders, not a setting: it sends nothing to anybody, it only
 * stops the list and the dashboard from counting as « waiting for a
 * signature » a contract nobody can sign any more. Skipped when the contracts
 * are switched off, like everything else they do.
 */
#[AsMessageHandler]
final readonly class ExpireLapsedContractsHandler
{
    public function __construct(
        private ContractAccessLinkManagerInterface $links,
        private StudioContext $studio,
    ) {}

    public function __invoke(ExpireLapsedContractsMessage $message): void
    {
        if (!$this->studio->areContractsEnabled()) {
            return;
        }

        $this->links->expireLapsed();
    }
}
