<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Comment\Entity;

use Aurora\Module\Notes\Comment\Repository\NoteCommentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteCommentRepository::class)]
#[ORM\Table(name: 'core_notes_comments')]
class NoteComment extends AbstractNoteComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_comment_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
