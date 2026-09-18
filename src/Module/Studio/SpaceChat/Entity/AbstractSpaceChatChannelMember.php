<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person in a room.
 *
 * **Two nullable relations and a label, like a message.** A member is either an
 * account or an address, never both, and both relations are `SET NULL`:
 * deleting an account or revoking an address must not take the row with it, or
 * a private conversation would lose the person it was with and become a
 * conversation with nobody. The label is written once, when the person is
 * added, and is what the interface prints from then on.
 *
 * Rooms opened by the studio carry only accounts: the client's side of a room
 * is a flag on the room itself, for the reasons {@see AbstractSpaceChatChannel}
 * gives. A private conversation is the one place an address appears here, and
 * it is the whole point of allowing one: the client talks to somebody, not to
 * the space.
 */
#[ORM\MappedSuperclass]
abstract class AbstractSpaceChatChannelMember implements SpaceChatChannelMemberInterface
{
    #[ORM\ManyToOne(targetEntity: SpaceChatChannelInterface::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected SpaceChatChannelInterface $channel;

    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $user = null;

    #[ORM\ManyToOne(targetEntity: SpaceAccessLinkInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?SpaceAccessLinkInterface $link = null;

    /** Who this is, as the room will always show them. */
    #[ORM\Column(length: 180)]
    protected string $label;

    /** Which side of the space this person is on, independently of the rows above. */
    #[ORM\Column(options: ['default' => false])]
    protected bool $fromClient = false;

    #[ORM\Column(type: 'datetime_immutable')]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

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
        $this->fromClient = false;

        return $this;
    }

    public function getLink(): ?SpaceAccessLinkInterface
    {
        return $this->link;
    }

    public function setLink(?SpaceAccessLinkInterface $link): static
    {
        $this->link = $link;
        $this->fromClient = $link instanceof SpaceAccessLinkInterface;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isFromClient(): bool
    {
        return $this->fromClient;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
