<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\FollowUp\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Studio\Pipeline\FollowUp\Message\SendDueFollowUpsMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * The follow-ups ring once a day, in the morning at the site's time: a
 * follow-up is a day, and the first notification of that day is the useful
 * one. Hourly would only add chances of two in one day around a clock change.
 */
final readonly class FollowUpRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function __construct(
        private SiteTimezone $siteTimezone,
    ) {}

    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('30 8 * * *', new SendDueFollowUpsMessage(), $this->siteTimezone->get());
    }
}
