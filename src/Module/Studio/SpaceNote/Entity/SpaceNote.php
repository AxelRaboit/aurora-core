<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Entity;

use Aurora\Module\Studio\SpaceNote\Repository\SpaceNoteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceNoteRepository::class)]
#[ORM\Table(name: 'core_studio_space_notes')]
#[ORM\Index(name: 'idx_space_note_space_pinned', columns: ['space_id', 'pinned'])]
#[ORM\HasLifecycleCallbacks]
class SpaceNote extends AbstractSpaceNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_note_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
