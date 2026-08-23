<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractPlanning implements PlanningInterface
{
    use TimestampableTrait;

    /** Highest slot the shared categorical palette defines. */
    public const int MAX_COLOUR_SLOT = 8;

    public const int DEFAULT_COLOUR_SLOT = 1;

    #[ORM\Column(length: 150)]
    protected string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    /**
     * Which of the shared palette's colours this calendar wears.
     *
     * A slot, not a hex value, which is what this column held before. Three
     * reasons, and the first is the one that matters: a stored hex cannot follow
     * the theme, so a colour chosen against a white page is whatever it happens
     * to be against a dark one. The palette has a step per mode for exactly
     * that. It is also already checked for separation under colour-vision
     * deficiency, which a free colour picker cannot promise - `#ff00ff` is a
     * legal answer and an unreadable one.
     *
     * Eight slots, assigned in order. A ninth calendar shares a colour with one
     * above it rather than getting a generated hue, because a generated hue is
     * indistinguishable from an existing one under CVD - see
     * `convention_chart_palette`.
     */
    #[ORM\Column(options: ['default' => self::DEFAULT_COLOUR_SLOT])]
    protected int $colourSlot = self::DEFAULT_COLOUR_SLOT;

    /**
     * The zone the calendar's days are cut in.
     *
     * On the calendar and not on each event: "all day" only means something in
     * one zone, and an event that carried its own would make a day-long event
     * start at 01:00 for a reader in another country.
     */
    #[ORM\Column(length: 64, options: ['default' => 'Europe/Paris'])]
    protected string $timezone = 'Europe/Paris';

    #[ORM\Column(length: 20, enumType: PlanningVisibilityEnum::class, options: ['default' => PlanningVisibilityEnum::Private->value])]
    protected PlanningVisibilityEnum $visibility = PlanningVisibilityEnum::Private;

    /**
     * Nullable, and stays nullable: deleting the account that made a calendar
     * must not take a team's shared calendar with it.
     */
    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $owner = null;

    /**
     * @var Collection<int, PlanningEventInterface>
     */
    #[ORM\OneToMany(targetEntity: PlanningEventInterface::class, mappedBy: 'planning', cascade: ['remove'], orphanRemoval: true)]
    protected Collection $events;

    public function __construct()
    {
        // Initialised here rather than at the property, per
        // `convention_collection_on_concrete`: an uninitialised collection is
        // null and the first `add()` is a crash.
        $this->events = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getColourSlot(): int
    {
        return $this->colourSlot;
    }

    /**
     * Clamped rather than trusted: a slot outside the palette draws nothing at
     * all, and a calendar with no colour is invisible on a month grid.
     */
    public function setColourSlot(int $colourSlot): static
    {
        $this->colourSlot = max(1, min(self::MAX_COLOUR_SLOT, $colourSlot));

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getVisibility(): PlanningVisibilityEnum
    {
        return $this->visibility;
    }

    public function setVisibility(PlanningVisibilityEnum $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getOwner(): ?CoreUserInterface
    {
        return $this->owner;
    }

    public function setOwner(?CoreUserInterface $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * @return Collection<int, PlanningEventInterface>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }
}
