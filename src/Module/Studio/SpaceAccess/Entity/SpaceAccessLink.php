<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Entity;

use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceAccessLinkRepository::class)]
#[ORM\Table(name: 'core_studio_space_access_links')]
class SpaceAccessLink extends AbstractSpaceAccessLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_access_link_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
