<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Service\SpaceAccessLinkLabel;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message on a piece of content, from either side.
 *
 * **One shared thread and not two mailboxes.** What the studio writes, the
 * client reads, and the other way round; a thread whose halves cannot see each
 * other is two people talking past each other with extra steps. Nothing here is
 * internal - the screens say so where somebody types, because a note written in
 * the wrong box is read by a customer.
 *
 * **The author is stored twice on purpose**, and that is the design decision
 * worth knowing. The relations say who while they last: an account can be
 * deleted, an address revoked and then deleted, and both are `SET NULL` because
 * removing a person must not remove what they said. But a message whose author
 * became null is a message nobody wrote, which is worse than useless in a
 * thread somebody is reading to settle a disagreement. So `authorLabel` and
 * `fromClient` are written once, at the moment the message is posted, and never
 * depend on a row that can go.
 *
 * It replaces the note that used to hang off the approval. That column was
 * cleared whenever the text was rewritten - which is exactly when the studio is
 * acting on what the client asked for - so the instruction disappeared at the
 * moment it was being used. A verdict is a state and can be reset; words are
 * events and are kept.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractSpaceContentComment implements SpaceContentCommentInterface
{
    #[ORM\ManyToOne(targetEntity: SpaceContentItemInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected SpaceContentItemInterface $item;

    #[ORM\Column(type: Types::TEXT)]
    protected string $body;

    /**
     * Who said it, as it will always be shown.
     *
     * A studio member's name, or the address a client's link was sent to -
     * there is no account behind a link, so the mailbox is the only name there
     * is. Written once and never recomputed: a rename of an account should not
     * rewrite the history of a conversation either.
     */
    #[ORM\Column(length: 180)]
    protected string $authorLabel;

    /** Which side of the thread this is on, independently of the rows below. */
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

    public function getItem(): SpaceContentItemInterface
    {
        return $this->item;
    }

    public function setItem(SpaceContentItemInterface $item): static
    {
        $this->item = $item;

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
