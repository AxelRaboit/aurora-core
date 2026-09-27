<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Studio\SpaceContent\Message\NotifyLateReviewsMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * The daily look at overdue reviews, in the morning, when somebody will read it.
 */
final class SpaceContentRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('30 7 * * *', new NotifyLateReviewsMessage());
    }
}
