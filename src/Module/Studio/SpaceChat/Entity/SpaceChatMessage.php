<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceChatMessageRepository::class)]
#[ORM\Table(name: 'core_studio_space_chat_messages')]
#[ORM\Index(name: 'idx_space_chat_space_created', columns: ['space_id', 'created_at'])]
class SpaceChatMessage extends AbstractSpaceChatMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_chat_message_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
