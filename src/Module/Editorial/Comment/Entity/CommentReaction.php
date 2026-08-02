<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Entity;

use Aurora\Module\Editorial\Comment\Repository\CommentReactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentReactionRepository::class)]
#[ORM\Table(name: 'core_comment_reactions')]
#[ORM\UniqueConstraint(name: 'uniq_comment_fingerprint', columns: ['comment_id', 'fingerprint'])]
class CommentReaction extends AbstractCommentReaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_comment_reaction_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
