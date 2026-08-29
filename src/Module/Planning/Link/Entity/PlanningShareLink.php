<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Entity;

use Aurora\Module\Planning\Link\Repository\PlanningShareLinkRepository;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningShareLinkRepository::class)]
#[ORM\Table(name: 'core_planning_share_links')]
// The lookup every guest request makes, on a column that is already unique - the
// index is the constraint, declared here so the mapping and the migration agree.
#[ORM\UniqueConstraint(name: 'uniq_planning_share_link_token', columns: ['token'])]
class PlanningShareLink extends AbstractPlanningShareLink
{
    #[ORM\ManyToMany(targetEntity: PlanningInterface::class)]
    // The join columns are named rather than left to Doctrine, which would derive
    // them from the class names and give `abstract_planning_share_link_id` and
    // `planning_interface_id` - the mapping's vocabulary leaking into the schema.
    #[ORM\JoinTable(name: 'core_planning_share_link_calendars')]
    #[ORM\JoinColumn(name: 'share_link_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'planning_id', onDelete: 'CASCADE')]
    protected Collection $calendars;

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
