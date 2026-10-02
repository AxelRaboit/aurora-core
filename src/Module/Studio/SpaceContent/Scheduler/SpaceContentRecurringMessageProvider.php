<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Studio\SpaceContent\Message\NotifyLateReviewsMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * The daily look at overdue reviews, in the morning, when somebody will read it.
 *
 * At the site's time (Settings > Localisation): 09:30, where "07:30" read in
 * UTC used to give 09:30 in a Paris summer and 08:30 in its winter.
 */
final readonly class SpaceContentRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function __construct(
        private SiteTimezone $siteTimezone,
    ) {}

    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('30 9 * * *', new NotifyLateReviewsMessage(), $this->siteTimezone->get());
    }
}
