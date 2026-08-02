<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Repository\PostSlugHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostSlugHistoryRepository::class)]
#[ORM\Table(name: 'core_post_slug_history')]
// findOneByLocaleAndSlug() answers the public 301 redirect and assumes one
// row at most: two would make an old URL land on an arbitrary post.
#[ORM\UniqueConstraint(name: 'uniq_post_slug_history_locale_slug', columns: ['locale', 'slug'])]
class PostSlugHistory extends AbstractPostSlugHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_post_slug_history_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
