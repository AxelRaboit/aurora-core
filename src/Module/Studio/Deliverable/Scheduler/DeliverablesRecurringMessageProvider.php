<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Studio\Deliverable\Message\PurgeTrashedDeliverablesMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * The deliverables' recurring jobs, contributed to core's main schedule.
 *
 * Nightly, at the hour the other trashes empty: nobody is waiting on it, and
 * one hour for all of them is easier to reason about than one each.
 */
final class DeliverablesRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('0 3 * * *', new PurgeTrashedDeliverablesMessage());
    }
}
