<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\Entity;

use Aurora\Module\Studio\SpaceDeliverable\Repository\SpaceDeliverableRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceDeliverableRepository::class)]
#[ORM\Table(name: 'core_studio_space_deliverables')]
#[ORM\Index(name: 'idx_space_deliverable_space', columns: ['space_id'])]
#[ORM\HasLifecycleCallbacks]
class SpaceDeliverable extends AbstractSpaceDeliverable
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_deliverable_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
