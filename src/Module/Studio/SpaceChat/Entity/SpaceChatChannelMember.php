<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceChatChannelMemberRepository::class)]
#[ORM\Table(name: 'core_studio_space_chat_channel_members')]
#[ORM\Index(name: 'idx_space_chat_member_channel', columns: ['channel_id'])]
class SpaceChatChannelMember extends AbstractSpaceChatChannelMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_chat_channel_member_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
