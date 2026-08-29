<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarkdownNoteRepository::class)]
#[ORM\Table(name: 'core_notes_markdown_notes')]
#[ORM\Index(name: 'idx_notes_markdown_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_notes_markdown_parent', columns: ['parent_id'])]
class MarkdownNote extends AbstractMarkdownNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_markdown_note_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    /**
     * On the concrete class, not the MappedSuperclass, per
     * `convention_collection_on_concrete`: a collection declared on a class with
     * no identifier gives Doctrine nothing to join against.
     *
     * @var Collection<int, MarkdownNoteInterface>
     */
    #[ORM\OneToMany(targetEntity: MarkdownNoteInterface::class, mappedBy: 'parent')]
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
