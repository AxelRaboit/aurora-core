<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceResource\Enum\SpaceResourceKindEnum;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An item pinned in a space: a link, a text, a contact.
 *
 * **What the space cannot produce by itself.** The board carries the work,
 * the files carry what was exchanged, the notes carry what the studio thinks.
 * What remains is everything that lives elsewhere and that people look for
 * every time: the mockup in Canva, the host's dashboard, the name of the
 * person who signs off on the client's side. That is what this table keeps,
 * in the place where people look for it.
 *
 * **Visibility is per item, and closed by default.** It is as much the
 * reason the feature exists as the list itself: what is shared and what is
 * not are filed in the same place, and a checkbox decides, item by item.
 * Closed by default, because a default value that publishes is discovered
 * after the fact.
 *
 * The filter is applied where the items are read, never at display time: a
 * hidden item does not leave the server for the client. Filtering in the
 * page would have turned a confidence into a display preference.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractSpaceResource implements SpaceResourceInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    /**
     * What the row is, and what decides the rest.
     *
     * See {@see SpaceResourceKindEnum}: the kind dictates what is required, what
     * is validated and how the row is drawn.
     */
    #[ORM\Column(length: 20, enumType: SpaceResourceKindEnum::class)]
    protected SpaceResourceKindEnum $kind;

    /** The word read in the list, whatever the kind. */
    #[ORM\Column(length: 180)]
    protected string $label;

    /**
     * The address, for a link.
     *
     * 2048 because that is what a shared address really takes: a Google
     * document or a Canva view carries a long identifier and parameters, and a
     * 255 column would have truncated them silently.
     */
    #[ORM\Column(length: 2048, nullable: true)]
    protected ?string $url = null;

    /** The body, for a text; a detail, for the other kinds. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $body = null;

    /** The address of a contact, when the row is one. */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $email = null;

    /** Their phone number. */
    #[ORM\Column(length: 30, nullable: true)]
    protected ?string $phone = null;

    /**
     * Whether the client sees this row.
     *
     * Closed by default: see the class header.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $visibleToClient = false;

    /**
     * The chosen order, not the order of arrival.
     *
     * A list of resources is a list people arrange: the link opened every day
     * moves to the top. Sorting by creation date would have imposed the
     * opposite - the last one added first, which is rarely the most useful.
     */
    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

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

    public function getKind(): SpaceResourceKindEnum
    {
        return $this->kind;
    }

    public function setKind(SpaceResourceKindEnum $kind): static
    {
        $this->kind = $kind;

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

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function isVisibleToClient(): bool
    {
        return $this->visibleToClient;
    }

    public function setVisibleToClient(bool $visibleToClient): static
    {
        $this->visibleToClient = $visibleToClient;

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
}
