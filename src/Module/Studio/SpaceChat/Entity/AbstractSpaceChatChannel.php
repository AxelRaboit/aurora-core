<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Enum\SpaceChatChannelKindEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One room inside a space's conversation.
 *
 * **A space used to hold exactly one stream, and that was the limit worth
 * lifting.** Everything that is not about one card landed in the same place:
 * next month's brief, the logo somebody owes, a campaign pushed back a week.
 * One room for all of it means the brief scrolls away under the logistics.
 *
 * **The rooms belong to the studio.** The client walks into the one that was
 * opened for them and can answer there; they do not get to open more, because
 * the rooms are how the studio organises its own work and a client inventing
 * one would be organising somebody else's. What the client can do is start a
 * private conversation, which is a different gesture: it names a person rather
 * than a subject.
 *
 * **Who is in the room is stored, and the client is not in that list.** A
 * member row names an account; the client holds an address, not an account, and
 * a space can have several addresses out at once. Listing them one by one would
 * mean revoking an address silently emptying a room. So the client's side is
 * one flag, {@see isOpenToClient()}: whoever holds a usable link to the space
 * reads the rooms that carry it, and nothing else.
 */
#[ORM\MappedSuperclass]
abstract class AbstractSpaceChatChannel implements SpaceChatChannelInterface
{
    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    /**
     * What the room is called, or who it is with.
     *
     * A private conversation carries a name too, written when it is opened, and
     * it is the other person's: the panel has to be able to list a conversation
     * without loading both participants to work out a title, and a participant
     * whose account is deleted must not leave a conversation with no name.
     */
    #[ORM\Column(length: 120)]
    protected string $name;

    #[ORM\Column(length: 20, enumType: SpaceChatChannelKindEnum::class, options: ['default' => 'topic'])]
    protected SpaceChatChannelKindEnum $kind = SpaceChatChannelKindEnum::Topic;

    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    /**
     * Whether whoever holds a link to this space reads this room.
     *
     * False on everything but the main room, which is the safe default: a room
     * opened to talk about a client should not become a room that client reads
     * because somebody forgot a checkbox.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $openToClient = false;

    /** @var Collection<int, SpaceChatChannelMemberInterface> */
    #[ORM\OneToMany(targetEntity: SpaceChatChannelMemberInterface::class, mappedBy: 'channel', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $members;

    #[ORM\Column(type: 'datetime_immutable')]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    abstract public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface
    {
        return $this->space;
    }

    public function setSpace(CustomerSpaceInterface $space): static
    {
        $this->space = $space;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getKind(): SpaceChatChannelKindEnum
    {
        return $this->kind;
    }

    public function setKind(SpaceChatChannelKindEnum $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isOpenToClient(): bool
    {
        return $this->openToClient;
    }

    public function setOpenToClient(bool $openToClient): static
    {
        $this->openToClient = $openToClient;

        return $this;
    }

    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(SpaceChatChannelMemberInterface $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setChannel($this);
        }

        return $this;
    }

    public function removeMember(SpaceChatChannelMemberInterface $member): static
    {
        $this->members->removeElement($member);

        return $this;
    }

    public function holds(CoreUserInterface $user): bool
    {
        foreach ($this->members as $member) {
            if ($member->getUser()?->getId() === $user->getId() && null !== $user->getId()) {
                return true;
            }
        }

        return false;
    }

    public function holdsLink(SpaceAccessLinkInterface $link): bool
    {
        foreach ($this->members as $member) {
            if ($member->getLink()?->getId() === $link->getId() && null !== $link->getId()) {
                return true;
            }
        }

        return false;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
