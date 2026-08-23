<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\MessageHandler;

use Aurora\Module\Planning\Event\Message\SendDuePlanningNotificationsMessage;
use Aurora\Module\Planning\Event\Service\PlanningNotifier;
use Aurora\Module\Planning\PlanningContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendDuePlanningNotificationsHandler
{
    public function __construct(
        private PlanningNotifier $notifier,
        private PlanningContext $planningContext,
    ) {}

    public function __invoke(SendDuePlanningNotificationsMessage $message): void
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
