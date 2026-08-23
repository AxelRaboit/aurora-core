<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\MessageHandler;

use Aurora\Module\Planning\Event\Message\SendDuePlanningRemindersMessage;
use Aurora\Module\Planning\Event\Service\PlanningReminderNotifier;
use Aurora\Module\Planning\PlanningContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendDuePlanningRemindersHandler
{
    public function __construct(
        private PlanningReminderNotifier $notifier,
        private PlanningContext $planningContext,
    ) {}

    public function __invoke(SendDuePlanningRemindersMessage $message): void
    {
        // Checked here and not only in the schedule: the toggle can go off while
        // the worker is running, and a module switched off should stop acting
        // rather than keep sending until the next deploy.
        if (!$this->planningContext->isBackendEnabled()) {
            return;
        }

        $this->notifier->sendDue();
    }
}
