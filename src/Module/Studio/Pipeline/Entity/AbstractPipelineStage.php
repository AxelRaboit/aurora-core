<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Entity;

use Aurora\Core\Support\ChartPalette;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\Pipeline\Enum\PipelineStageRoleEnum;
use Doctrine\ORM\Mapping as ORM;

/**
 * One column of the prospect pipeline: a step between meeting somebody and
 * working for them.
 *
 * **The stages belong to the installation, not to the product.** One studio
 * sends a proposal after a call, another after a workshop and a quote; a fixed
 * list would have been one fewer table and wrong for the second one. They are
 * seeded from a translated default list the first time the pipeline is read,
 * so the board is usable before anybody thinks about it - the same choice the
 * columns of a space's board made.
 *
 * Unlike those columns they are not attached to anything: there is one
 * pipeline, the studio's.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractPipelineStage implements PipelineStageInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 100)]
    protected string $name;

    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    /**
     * Which of the shared palette's colours this stage wears, or none.
     *
     * A slot rather than a hex value, for the reasons {@see ChartPalette}
     * gives: a stored colour cannot follow the theme.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $colourSlot = null;

    /** See {@see PipelineStageRoleEnum}: null for every stage in progress. */
    #[ORM\Column(length: 20, nullable: true, enumType: PipelineStageRoleEnum::class)]
    protected ?PipelineStageRoleEnum $role = null;

    abstract public function getId(): ?int;

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

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    public function setColourSlot(?int $colourSlot): static
    {
        $this->colourSlot = null === $colourSlot ? null : ChartPalette::clamp($colourSlot);

        return $this;
    }

    public function getRole(): ?PipelineStageRoleEnum
    {
        return $this->role;
    }

    public function setRole(?PipelineStageRoleEnum $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function isWon(): bool
    {
        return PipelineStageRoleEnum::Won === $this->role;
    }

    public function isLost(): bool
    {
        return PipelineStageRoleEnum::Lost === $this->role;
    }
}
