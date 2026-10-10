<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Studio\Contract\Message\ExpireLapsedContractsMessage;
use Aurora\Module\Studio\Contract\Message\NotifyEffectiveTerminationsMessage;
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
 * The hours are the site's (Settings > Localisation). They used to be read in
 * UTC, the process zone, so "07:15" went out at 09:15 in a Paris summer and
 * 08:15 in its winter; read at the site's time, the hours below keep the
 * summer ones and stop moving with the clock change.
 *
 * The handler decides whether to send anything at all: the feature is off
 * until somebody turns it on in the settings.
 */
final readonly class StudioRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function __construct(
        private SiteTimezone $siteTimezone,
    ) {}

    public function getRecurringMessages(): iterable
    {
        $zone = $this->siteTimezone->get();

        yield RecurringMessage::cron('15 9 * * *', new RemindUnsignedContractsMessage(), $zone);
        // Before the reminders, so a contract that lapsed overnight is not
        // chased the same morning.
        yield RecurringMessage::cron('10 9 * * *', new ExpireLapsedContractsMessage(), $zone);
        // Early, and quiet unless a sealed contract moved: then the
        // administrator has the list in the first mail of the day.
        yield RecurringMessage::cron('5 8 * * *', new VerifyContractsMessage(), $zone);
        // The day a termination takes effect, before the day's work starts.
        yield RecurringMessage::cron('0 8 * * *', new NotifyEffectiveTerminationsMessage(), $zone);
    }
}
