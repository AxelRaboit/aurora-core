<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Deck\Service\DeckPicture;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One file shown on a piece of content.
 *
 * **The file lives in the GED, not here.** A row says that a document is on an
 * item, in a given order, put there by someone; it holds no bytes, no address
 * and no thumbnail. An address copied here would be a second truth that goes
 * stale the day the file behind it is replaced, which is exactly the trap
 * {@see DeckPicture} exists to avoid for
 * slides. Everything drawable is resolved from the document at render time.
 *
 * **A join row and not a column on the item**, because the visual half of a
 * post is rarely one file - a carousel, a video and its cover, a press release
 * and the photo that goes with it. A single `document_id` would have forced the
 * first of those to be modelled as several posts.
 *
 * **`onDelete: CASCADE` on both sides, and the asymmetry with Editorial is
 * deliberate.** A post whose `ogImage` disappears is still a post, so that
 * relation is `SET NULL`. This row *is* the statement "this document is on this
 * item": with either end gone it asserts nothing, and a null document here
 * would render as an attachment nobody can open.
 *
 * **The author is stored twice**, for the reason spelled out on
 * {@see AbstractSpaceContentComment}: the relations say who while they last and
 * are `SET NULL` so that removing a person does not remove what they
 * contributed, while `authorLabel` and `fromClient` are written once at the
 * moment of the upload. "Who sent this photo" is a question asked about files
 * more often than about messages, and it must survive a revoked link.
 */
#[ORM\MappedSuperclass]
abstract class AbstractSpaceContentAttachment implements SpaceContentAttachmentInterface
{
    #[ORM\ManyToOne(targetEntity: SpaceContentItemInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected SpaceContentItemInterface $item;

    #[ORM\ManyToOne(targetEntity: DocumentInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected DocumentInterface $document;

    /**
     * Where the file sits among the others on the same item.
     *
     * A carousel is read in an order, and the order is a decision somebody
     * made rather than the order the files happened to be uploaded in.
     */
    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    /**
     * Who put it there, as it will always be shown.
     *
     * A studio member's name, or the address a client's link was sent to.
     * Written once and never recomputed.
     */
    #[ORM\Column(length: 180)]
    protected string $authorLabel;

    /** Which side it came from, independently of the rows below. */
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

    public function getDocument(): DocumentInterface
    {
        return $this->document;
    }

    public function setDocument(DocumentInterface $document): static
    {
        $this->document = $document;

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
     * Signs the upload as the studio.
     *
     * The label is passed rather than read off the account, because what a
     * `CoreUserInterface` is called is not part of that contract - the concrete
     * `User` has `getName()`, a substituted one need not.
     */
    public function addedByStudio(CoreUserInterface $user, string $label): static
    {
        $this->authorUser = $user;
        $this->authorLink = null;
        $this->authorLabel = $label;
        $this->fromClient = false;

        return $this;
    }

    /**
     * Signs the upload as the client.
     *
     * The address the link was issued to is the only name there is: there is no
     * account behind a link.
     */
    public function addedByClient(SpaceAccessLinkInterface $link): static
    {
        $this->authorLink = $link;
        $this->authorUser = null;
        $this->authorLabel = $link->getRecipientEmail();
        $this->fromClient = true;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
