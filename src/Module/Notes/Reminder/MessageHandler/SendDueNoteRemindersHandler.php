<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Reminder\MessageHandler;

use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Reminder\Message\SendDueNoteRemindersMessage;
use Aurora\Module\Notes\Reminder\Service\NoteReminders;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendDueNoteRemindersHandler
{
    public function __construct(
        private NoteReminders $reminders,
        private NotesContext $notesContext,
    ) {}

    public function __invoke(SendDueNoteRemindersMessage $message): void
    {
        // Checked here and not only in the schedule: a module switched off
        // stops ringing at once, not at the next deploy.
        if (!$this->notesContext->isMarkdownEnabled()) {
            return;
        }

        $this->reminders->sendDue();
    }
}
