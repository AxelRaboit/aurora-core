<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Studio\CustomerSpace\Message\PurgeTrashedSpacesMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * The client spaces' recurring jobs, contributed to core's main schedule.
 *
 * Nightly, at the hour the other trashes empty: nobody is waiting on it, and
 * one hour for all of them is easier to reason about than one each.
 */
final class CustomerSpacesRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('0 3 * * *', new PurgeTrashedSpacesMessage());
    }
}
