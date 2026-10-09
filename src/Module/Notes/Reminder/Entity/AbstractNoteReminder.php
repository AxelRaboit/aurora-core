<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Reminder\Entity;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * « Remind me of this note » (09/10/2026), as Craft and Notion offer it: a
 * notification in the suite at the chosen time, then nothing more.
 *
 * On the person and not on the note, like a favorite: in a shared space, a
 * reminder set by one reader must not ring for the others. One waiting
 * reminder per person and per note; a sent one stays, dated, until the note
 * or the person goes.
 */
#[ORM\MappedSuperclass]
#[ORM\Index(name: 'idx_notes_reminder_due', columns: ['sent_at', 'remind_at'])]
abstract class AbstractNoteReminder implements NoteReminderInterface
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    #[ORM\ManyToOne(targetEntity: MarkdownNoteInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected MarkdownNoteInterface $note;

    /** Stored in UTC, like every instant of the application. */
    #[ORM\Column]
    protected DateTimeImmutable $remindAt;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $sentAt = null;

    #[ORM\Column]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getUser(): CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(CoreUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getNote(): MarkdownNoteInterface
    {
        return $this->note;
    }

    public function setNote(MarkdownNoteInterface $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getRemindAt(): DateTimeImmutable
    {
        return $this->remindAt;
    }

    public function setRemindAt(DateTimeImmutable $remindAt): static
    {
        $this->remindAt = $remindAt;

        return $this;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
