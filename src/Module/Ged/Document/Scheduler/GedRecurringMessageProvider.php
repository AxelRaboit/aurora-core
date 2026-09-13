<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Ged\Document\Message\PurgeTrashedDocumentsMessage;
use Aurora\Module\Ged\DocumentCategory\Message\PurgeTrashedCategoriesMessage;
use Aurora\Module\Ged\DocumentFolder\Message\PurgeTrashedFoldersMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * GED's recurring jobs, contributed to core's main schedule.
 *
 * Nightly, at the same hour as the editorial purge: nobody is waiting on it,
 * and the two emptying the trash together is easier to reason about than two
 * unrelated hours.
 */
final class GedRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('0 3 * * *', new PurgeTrashedDocumentsMessage());

        // Documents first, by declaration order: it changes nothing about the
        // outcome - both foreign keys are `SET NULL` - but a document purged
        // while its folder still exists leaves a cleaner audit trail than the
        // other way round.
        yield RecurringMessage::cron('0 3 * * *', new PurgeTrashedFoldersMessage());
        yield RecurringMessage::cron('0 3 * * *', new PurgeTrashedCategoriesMessage());
    }
}
