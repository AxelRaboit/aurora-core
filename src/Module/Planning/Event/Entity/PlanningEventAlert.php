<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Entity;

use Aurora\Module\Planning\Event\Repository\PlanningEventAlertRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningEventAlertRepository::class)]
#[ORM\Table(name: 'core_planning_event_alerts')]
#[ORM\Index(name: 'idx_planning_alert_due', columns: ['remind_at', 'sent_at'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_alert_moment', columns: ['event_id', 'remind_at'])]
class PlanningEventAlert extends AbstractPlanningEventAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_planning_event_alert_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
