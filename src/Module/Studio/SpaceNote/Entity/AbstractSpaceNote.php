<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A note taken while working on a space.
 *
 * **The one thing in a space the client never sees.** The card threads and the
 * conversation are shared on purpose - what the studio writes there, the client
 * reads, and the screens say so where somebody types. A note is the other
 * thing: the brief you took down on the phone, the idea you are not ready to
 * propose, what went wrong last month. Those existed already, in the Notes
 * module, and were kept somewhere that has nothing to do with the client they
 * concern.
 *
 * Nothing here is ever serialised to the public page, and the module has no
 * public controller to serve it from.
 *
 * **The body is Editor.js blocks**, like a publication's zones: the same editor,
 * the same tools, and images that go through the same upload path - filed in
 * this space's own folder rather than loose in the library.
 *
 * The author is stored twice, the way the space's messages are: the relation
 * says who while it lasts and is `SET NULL`, because deleting an account must
 * not delete what they wrote, and a note whose author became null is a note
 * nobody took.
 */
#[ORM\MappedSuperclass]
abstract class AbstractSpaceNote implements SpaceNoteInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    #[ORM\Column(length: 180)]
    protected string $title;

    /**
     * The blocks, as the editor writes them.
     *
     * `json` and not `text`: it is a structure, and the only queries that ever
     * look inside it - which notes use this picture - are better served by the
     * usage provider than by a LIKE.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    protected array $body = [];

    /**
     * The colour of the post-it, when somebody chose one.
     *
     * A slot rather than a colour, like everywhere else: the page resolves it
     * against the palette the whole application shares, so a note follows the
     * theme instead of carrying a hex nobody can restyle. Null is a real
     * answer - a wall where every note is coloured is a wall where colour has
     * stopped meaning anything.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $colourSlot = null;

    /**
     * Pinned notes come first, whichever view is drawn.
     *
     * One flag rather than a free ordering: what a wall of notes needs is "this
     * one matters today", and a position column would have asked somebody to
     * maintain an order nobody reads.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $pinned = false;

    /** Who took it, as it will always be shown. */
    #[ORM\Column(length: 180)]
    protected string $authorLabel;

    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $author = null;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getBody(): array
    {
        return $this->body;
    }

    /** @param list<array<string, mixed>> $body */
    public function setBody(array $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    public function setColourSlot(?int $colourSlot): static
    {
        $this->colourSlot = $colourSlot;

        return $this;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): static
    {
        $this->pinned = $pinned;

        return $this;
    }

    public function getAuthorLabel(): string
    {
        return $this->authorLabel;
    }

    public function getAuthor(): ?CoreUserInterface
    {
        return $this->author;
    }

    /**
     * Signs the note.
     *
     * The label is passed rather than read off the account, because what a
     * `CoreUserInterface` is called is not part of that contract.
     */
    public function takenBy(CoreUserInterface $author, string $label): static
    {
        $this->author = $author;
        $this->authorLabel = $label;

        return $this;
    }
}
