<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * How far somebody has read a room.
 *
 * **Nothing said what was new.** A conversation had no read state at all:
 * the studio learnt of a client's message from the bell, then had to find
 * it, and a client had no way to tell the team had answered short of
 * scrolling. One row per reader and room now says up to when they read, and
 * every message after it, written by somebody else, is unread.
 *
 * A reader is an account on the studio's side, an access link on the
 * client's, never both: the same pair as a message's author. Written when
 * the room is on screen, not when the page loads - a space opened on its
 * calendar has read nothing of its conversation.
 */
#[ORM\MappedSuperclass]
abstract class AbstractSpaceChatReadMarker implements SpaceChatReadMarkerInterface
{
    #[ORM\ManyToOne(targetEntity: SpaceChatChannelInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected SpaceChatChannelInterface $channel;

    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?CoreUserInterface $user = null;

    #[ORM\ManyToOne(targetEntity: SpaceAccessLinkInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?SpaceAccessLinkInterface $link = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $readAt;

    abstract public function getId(): ?int;

    public function getChannel(): SpaceChatChannelInterface
    {
        return $this->channel;
    }

    public function setChannel(SpaceChatChannelInterface $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getUser(): ?CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(?CoreUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getLink(): ?SpaceAccessLinkInterface
    {
        return $this->link;
    }

    public function setLink(?SpaceAccessLinkInterface $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getReadAt(): DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(DateTimeImmutable $readAt): static
    {
        $this->readAt = $readAt;

        return $this;
    }
}
