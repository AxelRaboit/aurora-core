<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Editorial\Form\Message\PurgeFormSubmissionsMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * The forms submodule's own recurring job, kept apart from the posts one: they
 * share a module and nothing else, and a provider that reaches across
 * submodules is the first step to a schedule nobody can read.
 *
 * Nightly, at the same hour as the other purges - nobody is waiting on it.
 */
final class EditorialFormRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('0 3 * * *', new PurgeFormSubmissionsMessage());
    }
}
