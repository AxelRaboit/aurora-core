<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Entity;

use Aurora\Module\Studio\SpaceFile\Repository\SpaceFileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceFileRepository::class)]
#[ORM\Table(name: 'core_studio_space_files')]
#[ORM\Index(name: 'idx_space_file_space_created', columns: ['space_id', 'created_at'])]
class SpaceFile extends AbstractSpaceFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_file_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
