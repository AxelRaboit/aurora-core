<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Entity;

use Aurora\Module\Planning\Link\Repository\PlanningShareLinkRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningShareLinkRepository::class)]
#[ORM\Table(name: 'core_planning_share_links')]
// The lookup every guest request makes, on a column that is already unique - the
// index is the constraint, declared here so the mapping and the migration agree.
#[ORM\UniqueConstraint(name: 'uniq_planning_share_link_token', columns: ['token'])]
class PlanningShareLink extends AbstractPlanningShareLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_planning_share_link_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
