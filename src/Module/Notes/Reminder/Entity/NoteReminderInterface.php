<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Reminder\Entity;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface NoteReminderInterface
{
    public function getId(): ?int;

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function getNote(): MarkdownNoteInterface;

    public function setNote(MarkdownNoteInterface $note): static;

    public function getRemindAt(): DateTimeImmutable;

    public function setRemindAt(DateTimeImmutable $remindAt): static;

    public function getSentAt(): ?DateTimeImmutable;

    public function setSentAt(?DateTimeImmutable $sentAt): static;

    public function getCreatedAt(): DateTimeImmutable;
}
