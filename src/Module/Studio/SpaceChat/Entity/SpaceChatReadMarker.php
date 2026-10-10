<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatReadMarkerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceChatReadMarkerRepository::class)]
#[ORM\Table(name: 'core_studio_space_chat_read_markers')]
#[ORM\UniqueConstraint(name: 'uniq_space_chat_read_user', columns: ['channel_id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_space_chat_read_link', columns: ['channel_id', 'link_id'])]
class SpaceChatReadMarker extends AbstractSpaceChatReadMarker
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_chat_read_marker_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
