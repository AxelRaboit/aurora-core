<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Reading\Entity;

use Aurora\Module\Editorial\Post\Reading\Repository\PostReadingLinkRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostReadingLinkRepository::class)]
#[ORM\Table(name: 'core_post_reading_links')]
#[ORM\Index(name: 'idx_core_post_reading_links_post', columns: ['post_id'])]
class PostReadingLink extends AbstractPostReadingLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_post_reading_link_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
