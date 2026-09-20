<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Entity;

use Aurora\Module\Studio\SpaceResource\Repository\SpaceResourceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceResourceRepository::class)]
#[ORM\Table(name: 'core_studio_space_resources')]
#[ORM\Index(name: 'idx_space_resource_space_position', columns: ['space_id', 'position'])]
#[ORM\HasLifecycleCallbacks]
class SpaceResource extends AbstractSpaceResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_resource_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
