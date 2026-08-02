<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Entity;

use Aurora\Module\Editorial\Comment\Repository\CommentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'core_comments')]
// The moderation queue and the public thread both ask for one post's comments
// in one status; nothing ever asks for a status across the whole site without
// paging.
#[ORM\Index(name: 'idx_comment_post_status', columns: ['post_id', 'status'])]
class Comment extends AbstractComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_comment_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
