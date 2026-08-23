<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Share\Entity;

use Aurora\Module\Planning\Share\Repository\PlanningShareRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningShareRepository::class)]
#[ORM\Table(name: 'core_planning_shares')]
// One share per person per calendar. Two rows for the same pair would be two
// answers to "may they write", and no way to say which is meant.
#[ORM\UniqueConstraint(name: 'uniq_planning_share', columns: ['planning_id', 'user_id'])]
// "Which calendars am I shared into" - asked on every page load by `findVisibleTo`.
#[ORM\Index(name: 'idx_planning_share_user', columns: ['user_id'])]
class PlanningShare extends AbstractPlanningShare
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_planning_share_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
