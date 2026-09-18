<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceChatChannelRepository::class)]
#[ORM\Table(name: 'core_studio_space_chat_channels')]
#[ORM\Index(name: 'idx_space_chat_channel_space', columns: ['space_id', 'position'])]
class SpaceChatChannel extends AbstractSpaceChatChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_chat_channel_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
