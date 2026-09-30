<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Favorite\Entity;

use Aurora\Module\Notes\Favorite\Repository\NoteFavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteFavoriteRepository::class)]
#[ORM\Table(name: 'core_notes_favorites')]
class NoteFavorite extends AbstractNoteFavorite
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_favorite_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
