<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Entity;

use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningRepository::class)]
#[ORM\Table(name: 'core_plannings')]
// Declared here as well as in the migration, or the two disagree: a unique index
// only Postgres knows about is one `schema:update` proposes to drop. It was
// invisible on a database carrying the orphan sequences of the module split, and
// obvious the first time the migrations ran on an empty one.
#[ORM\UniqueConstraint(name: 'uniq_planning_source', columns: ['source_type'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_feed_token', columns: ['feed_token'])]
class Planning extends AbstractPlanning
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_planning_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
