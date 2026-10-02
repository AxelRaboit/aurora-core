<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\Entity;

use Aurora\Module\Studio\SpaceDeliverable\Repository\SpaceDeliverableLinkRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceDeliverableLinkRepository::class)]
#[ORM\Table(name: 'core_studio_space_deliverable_links')]
#[ORM\Index(name: 'idx_space_deliverable_link_deliverable', columns: ['deliverable_id'])]
class SpaceDeliverableLink extends AbstractSpaceDeliverableLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_deliverable_link_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
