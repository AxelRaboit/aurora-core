<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
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
 * No colour and no "is this the last one" flag. Both were in the board this is
 * modelled on and neither had a reader here: a column is identified by its name
 * and its place, and a terminal step that nothing acts on is a column like any
 * other.
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
}
