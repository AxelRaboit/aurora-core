<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Entity;

use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningEventRepository::class)]
#[ORM\Table(name: 'core_planning_events')]
#[ORM\Index(name: 'idx_planning_event_planning_start', columns: ['planning_id', 'start_at'])]
// The window query for series: which of them could reach the range being drawn.
// Declared here as well as created by the migration, or `schema:update` proposes
// to drop it for ever - which is what a partial index did until this was fixed.
#[ORM\Index(name: 'idx_planning_event_series', columns: ['planning_id', 'recurrence_until'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_event_source', columns: ['source_type', 'source_id'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_event_occurrence', columns: ['master_id', 'occurrence_at'])]
class PlanningEvent extends AbstractPlanningEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_planning_event_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
