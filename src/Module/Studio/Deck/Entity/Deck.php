<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Entity;

use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeckRepository::class)]
#[ORM\Table(name: 'core_decks')]
#[ORM\Index(name: 'idx_deck_deleted_at', columns: ['deleted_at'])]
class Deck extends AbstractDeck
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_deck_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
