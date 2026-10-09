<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Scheduler;

use Aurora\Core\Scheduler\RecurringMessageProviderInterface;
use Aurora\Module\Notes\Markdown\Message\PurgeTrashedNotesMessage;
use Aurora\Module\Notes\Reminder\Message\SendDueNoteRemindersMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Notes' recurring jobs, contributed to core's main schedule.
 *
 * Nightly, at the hour the other trashes empty: nobody is waiting on it, and
 * one hour for all of them is easier to reason about than three.
 *
 * The reminders every minute, as the calendar's alerts: « at 9:00 » has to
 * mean 9:00.
 */
final class NotesRecurringMessageProvider implements RecurringMessageProviderInterface
{
    public function getRecurringMessages(): iterable
    {
        yield RecurringMessage::cron('0 3 * * *', new PurgeTrashedNotesMessage());
        yield RecurringMessage::cron('* * * * *', new SendDueNoteRemindersMessage());
    }
}
