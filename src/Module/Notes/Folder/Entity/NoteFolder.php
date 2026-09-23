<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Entity;

use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteFolderRepository::class)]
#[ORM\Table(name: 'core_notes_markdown_folders')]
#[ORM\Index(name: 'idx_notes_folders_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_notes_folders_parent', columns: ['parent_id'])]
#[ORM\Index(name: 'idx_notes_folders_deleted_at', columns: ['deleted_at'])]
#[ORM\Index(name: 'idx_notes_folders_trashed_with', columns: ['trashed_with_folder_id'])]
#[ORM\Index(name: 'idx_notes_folders_favorited', columns: ['favorited_at'])]
class NoteFolder extends AbstractNoteFolder
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_markdown_folder_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    /**
     * On the concrete class, not the MappedSuperclass, per
     * `convention_collection_on_concrete`: a collection declared on a class
     * with no identifier gives Doctrine nothing to join against.
     *
     * @var Collection<int, NoteFolderInterface>
     */
    #[ORM\OneToMany(targetEntity: NoteFolderInterface::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChildren(): Collection
    {
        return $this->children;
    }
}
