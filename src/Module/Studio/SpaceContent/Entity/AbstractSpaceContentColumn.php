<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Core\Support\ChartPalette;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\ClientVisibility;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentColumnManager;
use Doctrine\ORM\Mapping as ORM;

/**
 * One column of a space's board, which is one step of its content's life.
 *
 * **The columns belong to the space, not to the product.** A client whose posts
 * go through a legal review has a step nobody else has, and a client who
 * publishes the day something is written has fewer. A fixed set would have been
 * one fewer table and wrong for the second customer.
 *
 * They are created with the space, from a default list, so a board is usable
 * the moment it exists. That list is a translation rather than a constant: the
 * names are data somebody renames, and they should arrive in the language the
 * person who created the space was reading.
 *
 * **A colour, added after the board was used.** This docblock argued against
 * one - that a step is identified by its name and its place - and being wrong
 * about it is worth recording rather than quietly overwriting. A board is not
 * read, it is scanned; and on the calendar a card wants to say "still to
 * approve" without being clicked. A name does that when you read it, a colour
 * does it when you glance.
 *
 * Still no "is this the last one" flag: it had the same argument and has not
 * acquired a reader.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractSpaceContentColumn implements SpaceContentColumnInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class, inversedBy: 'contentColumns')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    #[ORM\Column(length: 100)]
    protected string $name;

    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    /**
     * Which of the shared palette's colours this step wears, or none.
     *
     * A slot rather than a hex value, for the reasons {@see ChartPalette}
     * gives: a stored colour cannot follow the theme, and the eight tokens are
     * already checked for separation under colour-vision deficiency.
     *
     * **Nullable, and null is a real answer.** A board where every step is
     * coloured is a board where colour has stopped meaning anything; the point
     * is that two or three stand out. The steps a new space arrives with carry
     * slots anyway, because a feature nobody can see is a feature nobody uses.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $colourSlot = null;

    /**
     * Whether the client sees this stage.
     *
     * **The column is the right granularity, not the card.** A board already
     * says "what is at this stage"; "this stage is none of the client's
     * business" therefore sits naturally on it. Marking card by card would
     * mean thinking of it at every card created, which nobody does, and the
     * first one forgotten would cancel the protection.
     *
     * **Hidden by default**, like everything a space can show the client (the
     * common rule of {@see ClientVisibility}): a stage added to the board is a
     * work stage until somebody with the right to share has shown it. The
     * board a space receives at birth makes an exception for two stages,
     * Relecture, where the client answers, and Publié, what has gone out: see
     * {@see SpaceContentColumnManager::seedDefaults()}.
     *
     * Stages from before the rule kept their state: the migration only
     * changes the default, so nothing the client saw disappears.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $visibleToClient = false;

    /**
     * Which shared stage this step stands for, or none.
     *
     * Null is a real answer: a step called « Chez Marc » is no stage of the
     * common vocabulary, and forcing one on it would make the counts lie.
     */
    #[ORM\Column(length: 20, nullable: true, enumType: SpaceContentColumnRoleEnum::class)]
    protected ?SpaceContentColumnRoleEnum $role = null;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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

    public function getRole(): ?SpaceContentColumnRoleEnum
    {
        return $this->role;
    }

    public function setRole(?SpaceContentColumnRoleEnum $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    public function setColourSlot(?int $colourSlot): static
    {
        $this->colourSlot = null === $colourSlot ? null : ChartPalette::clamp($colourSlot);

        return $this;
    }
}
