<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeliverableLinkRepository::class)]
#[ORM\Table(name: 'core_studio_deliverable_links')]
#[ORM\Index(name: 'idx_deliverable_link_deliverable', columns: ['deliverable_id'])]
class DeliverableLink extends AbstractDeliverableLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_deliverable_link_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
