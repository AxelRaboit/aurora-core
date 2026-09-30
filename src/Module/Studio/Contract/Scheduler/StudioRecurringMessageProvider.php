<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Studio\Contract\Message\ExpireLapsedContractsMessage;
use Aurora\Module\Studio\Contract\Message\RemindUnsignedContractsMessage;
use Aurora\Module\Studio\Contract\Message\VerifyContractsMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Studio's recurring job, contributed to core's main schedule.
 *
 * Once a day, and in the morning: a reminder is a piece of mail a person will
 * read, so it goes out at an hour when that is plausible. Every minute would
 * be pointless - nothing about "three days without an answer" changes between
 * 08:15 and 08:16 - and hourly would only multiply the chances of sending two
 * in one day after a clock change.
 *
 * The handler decides whether to send anything at all: the feature is off
 * until somebody turns it on in the settings.
 */
final class StudioRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('15 7 * * *', new RemindUnsignedContractsMessage());
        // Before the reminders, so a contract that lapsed overnight is not
        // chased the same morning.
        yield RecurringMessage::cron('10 7 * * *', new ExpireLapsedContractsMessage());
        // Early, and quiet unless a sealed contract moved: then the
        // administrator has the list in the first mail of the day.
        yield RecurringMessage::cron('5 6 * * *', new VerifyContractsMessage());
    }
}
