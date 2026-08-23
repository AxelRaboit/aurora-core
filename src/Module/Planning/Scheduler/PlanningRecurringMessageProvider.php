<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Planning\Event\Message\SendDuePlanningNotificationsMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * The calendar's recurring job, contributed to core's main schedule.
 *
 * Every minute, for the reason Editorial's publication job runs every minute: "30
 * minutes before" has to mean 30 minutes, and a five-minute cron would make every
 * alert up to five minutes late.
 */
final class PlanningRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('* * * * *', new SendDuePlanningNotificationsMessage());
    }
}
