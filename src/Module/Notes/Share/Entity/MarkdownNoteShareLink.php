<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Entity;

use Aurora\Module\Notes\Share\Repository\MarkdownNoteShareLinkRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarkdownNoteShareLinkRepository::class)]
#[ORM\Table(name: 'core_notes_markdown_share_links')]
#[ORM\Index(name: 'idx_notes_share_link_note', columns: ['note_id'])]
class MarkdownNoteShareLink extends AbstractMarkdownNoteShareLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_markdown_share_link_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
