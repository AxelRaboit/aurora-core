<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarkdownNoteRepository::class)]
#[ORM\Table(name: 'core_notes_markdown_notes')]
#[ORM\Index(name: 'idx_notes_markdown_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_notes_markdown_folder', columns: ['folder_id'])]
#[ORM\Index(name: 'idx_notes_md_deleted_at', columns: ['deleted_at'])]
#[ORM\Index(name: 'idx_notes_md_trashed_with', columns: ['trashed_with_folder_id'])]
class MarkdownNote extends AbstractMarkdownNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_markdown_note_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
