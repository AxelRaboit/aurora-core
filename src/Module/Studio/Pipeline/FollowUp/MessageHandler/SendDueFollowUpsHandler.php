<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\FollowUp\MessageHandler;

use Aurora\Module\Studio\Pipeline\FollowUp\FollowUpReminders;
use Aurora\Module\Studio\Pipeline\FollowUp\Message\SendDueFollowUpsMessage;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendDueFollowUpsHandler
{
    public function __construct(
        private FollowUpReminders $reminders,
        private StudioContext $studioContext,
    ) {}

    public function __invoke(SendDueFollowUpsMessage $message): void
    {
        // Checked here and not only in the schedule: a feature switched off
        // stops ringing at once, not at the next deploy.
        if (!$this->studioContext->areCustomersEnabled()) {
            return;
        }

        $this->reminders->sendDue();
    }
}
