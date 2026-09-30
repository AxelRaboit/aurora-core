<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Entity;

use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteSpaceRepository::class)]
#[ORM\Table(name: 'core_notes_spaces')]
#[ORM\Index(name: 'idx_notes_spaces_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_notes_spaces_access', columns: ['access'])]
#[ORM\Index(name: 'idx_notes_spaces_deleted_at', columns: ['deleted_at'])]
class NoteSpace extends AbstractNoteSpace
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_space_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    /**
     * Sur la classe concrète, per `convention_collection_on_concrete`.
     *
     * @var Collection<int, NoteSpaceMemberInterface>
     */
    #[ORM\OneToMany(targetEntity: NoteSpaceMemberInterface::class, mappedBy: 'space', cascade: ['persist'], orphanRemoval: true)]
    protected Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMembers(): Collection
    {
        return $this->members;
    }
}
