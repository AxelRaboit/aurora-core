<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Reminder\Entity;

use Aurora\Module\Notes\Reminder\Repository\NoteReminderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteReminderRepository::class)]
#[ORM\Table(name: 'core_notes_reminders')]
class NoteReminder extends AbstractNoteReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_reminder_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
