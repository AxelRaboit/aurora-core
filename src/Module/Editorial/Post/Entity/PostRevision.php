<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Repository\PostRevisionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostRevisionRepository::class)]
#[ORM\Table(name: 'core_post_revisions')]
// The only way revisions are ever read: a post's, newest first.
#[ORM\Index(name: 'idx_post_revision_post_created', columns: ['post_id', 'created_at'])]
class PostRevision extends AbstractPostRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_post_revision_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
