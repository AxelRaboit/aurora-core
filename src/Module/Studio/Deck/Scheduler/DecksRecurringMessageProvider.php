<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Studio\Deck\Message\PurgeTrashedDecksMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * The presentations' recurring jobs, contributed to core's main schedule.
 *
 * Nightly, at the hour the other trashes empty.
 */
final class DecksRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('0 3 * * *', new PurgeTrashedDecksMessage());
    }
}
