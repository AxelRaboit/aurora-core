<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Service\SpaceAccessLinkLabel;
use Aurora\Module\Studio\SpaceContent\Entity\AbstractSpaceContentComment;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message in a space's own conversation, from either side.
 *
 * **It hangs off the space and not off a card, and that is the whole reason it
 * exists.** {@see AbstractSpaceContentComment} already carries everything said
 * about one piece of content, and says it better: a message there is filed
 * against the wording it is about, and somebody reopening the card a month
 * later finds the objection next to what was objected to. Nothing here replaces
 * that. What had nowhere to go was everything that is not about one card - the
 * brief for next month, a campaign being pushed back a week, "who is sending me
 * the logo" - and those were landing on whichever card happened to be open,
 * which is where they stopped being findable.
 *
 * The author is stored twice, for the reason the card threads give: the
 * relations say who while they last and are both `SET NULL`, because removing
 * an account or revoking an address must not remove what the person said, and a
 * message whose author became null is a message nobody wrote. `authorLabel` and
 * `fromClient` are written once, when the message is posted, and never depend
 * on a row that can go.
 *
 * Nothing here is internal either. The studio and the client read one stream,
 * and the box says so where somebody types.
 */
#[ORM\MappedSuperclass]
abstract class AbstractSpaceChatMessage implements SpaceChatMessageInterface
{
    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    /**
     * The room it was said in.
     *
     * Kept beside the space rather than instead of it, and that is not a
     * duplicate worth removing: every query the panel makes is "this room's
     * last messages", but the counters, the notifier and the deletion path all
     * ask "this space's", and going through the rooms to answer that would turn
     * one index into a join on every one of them.
     */
    #[ORM\ManyToOne(targetEntity: SpaceChatChannelInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected SpaceChatChannelInterface $channel;

    #[ORM\Column(type: Types::TEXT)]
    protected string $body;

    /**
     * Who said it, as it will always be shown.
     *
     * A studio member's name, or the address a client's link was sent to -
     * there is no account behind a link, so the mailbox is the only name there
     * is.
     */
    #[ORM\Column(length: 180)]
    protected string $authorLabel;

    /** Which side of the conversation this is on, independently of the rows below. */
    #[ORM\Column(options: ['default' => false])]
    protected bool $fromClient = false;

    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $authorUser = null;

    #[ORM\ManyToOne(targetEntity: SpaceAccessLinkInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?SpaceAccessLinkInterface $authorLink = null;

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

    public function getSpace(): CustomerSpaceInterface
    {
        return $this->space;
    }

    public function setSpace(CustomerSpaceInterface $space): static
    {
        $this->space = $space;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getAuthorLabel(): string
    {
        return $this->authorLabel;
    }

    public function isFromClient(): bool
    {
        return $this->fromClient;
    }

    public function getAuthorUser(): ?CoreUserInterface
    {
        return $this->authorUser;
    }

    public function getAuthorLink(): ?SpaceAccessLinkInterface
    {
        return $this->authorLink;
    }

    /**
     * Signs the message as the studio.
     *
     * The label is passed rather than read off the account, because what a
     * `CoreUserInterface` is called is not part of that contract - the concrete
     * `User` has `getName()`, a substituted one need not.
     */
    public function writtenByStudio(CoreUserInterface $user, string $label): static
    {
        $this->authorUser = $user;
        $this->authorLink = null;
        $this->authorLabel = $label;
        $this->fromClient = false;

        return $this;
    }

    public function writtenByClient(SpaceAccessLinkInterface $link): static
    {
        $this->authorLink = $link;
        $this->authorUser = null;
        // **Le libellé du lien, jamais son adresse.** Une adresse en
        // signature est lue par tous les autres invités du même espace, et un
        // espace en compte plusieurs. Le libellé est obligatoire depuis
        // {@see SpaceAccessLinkInput}, le repli ne sert donc qu'aux liens
        // émis avant cette règle.
        $this->authorLabel = SpaceAccessLinkLabel::of($link);
        $this->fromClient = true;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
