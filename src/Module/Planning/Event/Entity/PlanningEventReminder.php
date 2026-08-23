<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Entity;

use Aurora\Module\Planning\Event\Repository\PlanningEventReminderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningEventReminderRepository::class)]
#[ORM\Table(name: 'core_planning_event_reminders')]
#[ORM\Index(name: 'idx_planning_reminder_due', columns: ['remind_at', 'sent_at'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_reminder_offset', columns: ['event_id', 'minutes_before'])]
class PlanningEventReminder extends AbstractPlanningEventReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_planning_event_reminder_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
