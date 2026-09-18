<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Entity;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Entity\AbstractSpaceContentAttachment;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One file that belongs to the space itself, on no card.
 *
 * **Its own row rather than a nullable card.**
 * {@see AbstractSpaceContentAttachment} says one precise thing - this document
 * is on this card - and cascades from both ends because with either gone it
 * asserts nothing. A null card there would make the same row mean two
 * different statements, and every reader would have to ask which one it is.
 * This table says the other statement: this document belongs to this space.
 *
 * **The charter, the logos, the brief, a signed PDF.** The material of a
 * mission that is not the illustration of a post. It used to be pinned to
 * whichever card happened to be open, which is the same failure the space's
 * conversation was built to end for messages.
 *
 * **The file itself lives in GED**, like every other file of a space: filed as
 * a draft in this space's own folder, so it has no guessable public address,
 * it keeps its thumbnails, its trash and its usage registry. What is stored
 * here is the belonging, never the bytes nor an address - see the attachment's
 * own note for why a copied URL is a second truth.
 *
 * **The client sees them.** A space is shared: its cards, its files and its
 * conversation are read from both sides, and the studio's private surface is
 * the notes, which have no public route at all.
 *
 * The author is stored twice, as everywhere else in a space: the relations say
 * who while they last and are `SET NULL`, while the label and `fromClient` are
 * written once. "Who sent this" is asked about files long after a link is
 * revoked.
 */
#[ORM\MappedSuperclass]
abstract class AbstractSpaceFile implements SpaceFileInterface
{
    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    #[ORM\ManyToOne(targetEntity: DocumentInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected DocumentInterface $document;

    /** Who put it there, as it will always be shown. */
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

    public function getSpace(): CustomerSpaceInterface
    {
        return $this->space;
    }

    public function setSpace(CustomerSpaceInterface $space): static
    {
        $this->space = $space;

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

    public function addedByStudio(CoreUserInterface $user, string $label): static
    {
        $this->authorUser = $user;
        $this->authorLink = null;
        $this->authorLabel = $label;
        $this->fromClient = false;

        return $this;
    }

    /**
     * Signe le dépôt comme le client, l'adresse du lien pour seul nom.
     *
     * **Aucune route publique n'y mène aujourd'hui** : le client lit les
     * fichiers de l'espace, il n'en dépose pas. Ce qui est gardé ici est la
     * capacité, avec sa colonne et sa relation, parce que le jour où un lien
     * pourra déposer, ce sera une route à écrire et pas une migration à
     * passer. Les pièces jointes d'une fiche, elles, empruntent déjà ce
     * chemin.
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
