<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Studio\ClientNotice\Message\PurgeSeenClientNoticesMessage;
use Aurora\Module\Studio\ClientNotice\Message\RemindClientReviewDeadlinesMessage;
use Aurora\Module\Studio\ClientNotice\Message\SendDailyClientDigestsMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * The client's mornings, at the site's time.
 *
 * Deadlines first, at 07:30, so that a space mailing once a day carries them
 * in its 08:00 digest, and a space mailing after half an hour sends them at
 * eight as well. Then the nightly forgetting of what was read long ago.
 */
final readonly class ClientNoticeRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function __construct(
        private SiteTimezone $siteTimezone,
    ) {}

    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('30 7 * * *', new RemindClientReviewDeadlinesMessage(), $this->siteTimezone->get());
        yield RecurringMessage::cron('0 8 * * *', new SendDailyClientDigestsMessage(), $this->siteTimezone->get());
        yield RecurringMessage::cron('20 3 * * *', new PurgeSeenClientNoticesMessage());
    }
}
