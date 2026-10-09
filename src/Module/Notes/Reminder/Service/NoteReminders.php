<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Reminder\Service;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Reminder\Entity\NoteReminder;
use Aurora\Module\Notes\Reminder\Entity\NoteReminderInterface;
use Aurora\Module\Notes\Reminder\Repository\NoteReminderRepository;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Setting, clearing and sending the reminders of notes (09/10/2026).
 *
 * Sent by the worker every minute: « at 9:00 » has to mean 9:00, as for the
 * calendar's alerts.
 */
final readonly class NoteReminders
{
    public function __construct(
        private NoteReminderRepository $repository,
        private EntityManagerInterface $entityManager,
        private NotificationManagerInterface $notifications,
        private NoteSpaceAccess $spaceAccess,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    public function waiting(CoreUserInterface $user, MarkdownNoteInterface $note): ?NoteReminderInterface
    {
        return $this->repository->findWaiting($user, $note);
    }

    /** Sets the person's reminder on the note, replacing the one waiting. */
    public function set(CoreUserInterface $user, MarkdownNoteInterface $note, DateTimeImmutable $at): NoteReminderInterface
    {
        $reminder = $this->repository->findWaiting($user, $note);
        if (!$reminder instanceof NoteReminderInterface) {
            $reminder = new NoteReminder();
            $reminder->setUser($user);
            $reminder->setNote($note);
            $this->entityManager->persist($reminder);
        }

        $reminder->setRemindAt($at->setTimezone(new DateTimeZone('UTC')));
        $this->entityManager->flush();

        return $reminder;
    }

    public function clear(CoreUserInterface $user, MarkdownNoteInterface $note): void
    {
        $reminder = $this->repository->findWaiting($user, $note);
        if ($reminder instanceof NoteReminderInterface) {
            $this->entityManager->remove($reminder);
            $this->entityManager->flush();
        }
    }

    /**
     * Rings every reminder whose time has come.
     *
     * A note gone to the trash, or one the person can no longer read, does
     * not ring: the reminder is marked sent all the same, so that it is not
     * looked at again every minute.
     *
     * @return int the notifications written
     */
    public function sendDue(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $sent = 0;

        foreach ($this->repository->findDue($now) as $reminder) {
            $reminder->setSentAt($now);
            $note = $reminder->getNote();
            $user = $reminder->getUser();
            if (null !== $note->getDeletedAt()) {
                continue;
            }

            if (!$this->spaceAccess->readableNote($user, (int) $note->getId()) instanceof MarkdownNoteInterface) {
                continue;
            }

            $title = mb_trim((string) $note->getTitle());
            $this->notifications->notify(
                $user,
                'notes.reminder',
                '' === $title ? $this->translator->trans('notes.markdown.untitled') : $title,
                $this->translator->trans('notes.markdown.reminder.body'),
                $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => $note->getId()]),
                ['noteId' => $note->getId()],
                flush: false,
            );
            ++$sent;
        }

        $this->entityManager->flush();

        return $sent;
    }
}
