<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeliverableRepository::class)]
#[ORM\Table(name: 'core_studio_deliverables')]
#[ORM\Index(name: 'idx_deliverable_space', columns: ['space_id'])]
#[ORM\Index(name: 'idx_deliverable_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_deliverable_category', columns: ['category_id'])]
#[ORM\HasLifecycleCallbacks]
class Deliverable extends AbstractDeliverable
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_deliverable_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
