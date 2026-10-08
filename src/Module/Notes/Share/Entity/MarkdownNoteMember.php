<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Entity;

use Aurora\Module\Notes\Share\Repository\MarkdownNoteMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarkdownNoteMemberRepository::class)]
#[ORM\Table(name: 'core_notes_markdown_note_members')]
#[ORM\Index(name: 'idx_notes_markdown_member_note', columns: ['note_id'])]
#[ORM\Index(name: 'idx_notes_markdown_member_user', columns: ['user_id'])]
class MarkdownNoteMember extends AbstractMarkdownNoteMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_markdown_note_member_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
