<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Preview\Entity;

use Aurora\Module\Editorial\Post\Preview\Repository\PostPreviewTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostPreviewTokenRepository::class)]
#[ORM\Table(name: 'core_post_preview_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_post_preview_token', columns: ['token'])]
// "Is there a live preview for this post" - asked every time the editor draws its
// preview button, so it is the lookup worth an index.
#[ORM\Index(name: 'idx_post_preview_post', columns: ['post_id'])]
class PostPreviewToken extends AbstractPostPreviewToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_post_preview_token_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
