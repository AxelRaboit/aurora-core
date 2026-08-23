<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Entity;

use Aurora\Module\Planning\Reminder\Repository\PlanningReminderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningReminderRepository::class)]
#[ORM\Table(name: 'core_planning_reminders')]
// The worker's query, once a minute for the life of the application: what is due,
// not yet announced, and not already done. All three columns, in the order the
// planner narrows them.
#[ORM\Index(name: 'idx_planning_reminder_due', columns: ['due_at', 'notified_at', 'completed_at'])]
// The grid's query: one calendar's reminders in a window.
#[ORM\Index(name: 'idx_planning_reminder_planning_due', columns: ['planning_id', 'due_at'])]
class PlanningReminder extends AbstractPlanningReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_planning_reminder_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
