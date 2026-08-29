<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Editorial\Post\Message\PublishScheduledPostsMessage;
use Aurora\Module\Editorial\Post\Message\PurgeExpiredPreviewTokensMessage;
use Aurora\Module\Editorial\Post\Message\PurgeTrashedPostsMessage;
use Aurora\Module\Editorial\Post\Message\UnpublishScheduledPostsMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Editorial's recurring jobs, contributed to core's main schedule.
 *
 * Publication and its opposite run every minute because "scheduled for 09:00"
 * should mean 09:00; purging runs nightly because nobody is waiting on it.
 */
final class EditorialRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('* * * * *', new PublishScheduledPostsMessage());
        // Same cadence as publishing, and for the same reason: "until 09:00"
        // should mean 09:00.
        yield RecurringMessage::cron('* * * * *', new UnpublishScheduledPostsMessage());
        yield RecurringMessage::cron('0 3 * * *', new PurgeTrashedPostsMessage());
        yield RecurringMessage::cron('0 3 * * *', new PurgeExpiredPreviewTokensMessage());
    }
}
