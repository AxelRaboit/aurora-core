<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Editorial\Post\Message\PublishScheduledPostsMessage;
use Aurora\Module\Editorial\Post\Message\PurgeTrashedPostsMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Editorial's recurring jobs, contributed to core's main schedule.
 *
 * Publication runs every minute because "scheduled for 09:00" should mean
 * 09:00; purging runs nightly because nobody is waiting on it.
 */
final class EditorialRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('* * * * *', new PublishScheduledPostsMessage());
        yield RecurringMessage::cron('0 3 * * *', new PurgeTrashedPostsMessage());
    }
}
