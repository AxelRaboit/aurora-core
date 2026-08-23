<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Planning\Entity\AbstractPlanning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractPlanningEvent implements PlanningEventInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 255)]
    protected string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    /**
     * Free text, deliberately. A room, an address, a meeting link: what a
     * location is depends entirely on the event, and a typed field would have to
     * guess which.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $location = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $startAt;

    /**
     * Always set, including for an all-day event.
     *
     * A nullable end would mean every reader works out how long the event lasts,
     * and a month grid has to know that to draw one chip across three days. So
     * the write boundary settles it once: an all-day event ends at the end of its
     * last day.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $endAt;

    #[ORM\Column(options: ['default' => false])]
    protected bool $allDay = false;

    #[ORM\Column(length: 20, enumType: PlanningEventStatusEnum::class, options: ['default' => PlanningEventStatusEnum::Confirmed->value])]
    protected PlanningEventStatusEnum $status = PlanningEventStatusEnum::Confirmed;

    /**
     * Where this event came from, when it did not come from the calendar.
     *
     * A publication with a date, an invoice due: another module says "this thing
     * of mine happens then" and it shows up here. The three columns together are
     * the provenance - what kind of thing, which one, and what to call it - and
     * the pair `(sourceType, sourceId)` is unique, so the same publication cannot
     * land twice.
     *
     * Null on an event somebody typed. `isFromModule()` is what screens ask, so
     * none of them reads the columns directly and none of them has to know that
     * a label without an id is not a real source.
     */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $sourceType = null;

    #[ORM\Column(nullable: true)]
    protected ?int $sourceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $sourceLabel = null;

    /**
     * Where to send a reader who wants the thing itself.
     *
     * Separate from the label because they answer different questions - what this
     * came from, and where it lives. An event a module owns cannot be edited
     * here, so going to the source is the only useful gesture left, and without
     * this column the screen could only name it.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $sourceUrl = null;

    /**
     * A colour of this event's own, or null to take the calendar's.
     *
     * Null is the common case and the useful default: a calendar's colour is how
     * you tell whose an event is at a glance, and an event that quietly picked
     * its own would break that reading for everything around it. The override
     * exists for the one meeting in the week that has to stand out.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $colourSlot = null;

    /**
     * @var Collection<int, PlanningEventAlertInterface>
     */
    #[ORM\OneToMany(targetEntity: PlanningEventAlertInterface::class, mappedBy: 'event', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $alerts;

    #[ORM\ManyToOne(targetEntity: PlanningInterface::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PlanningInterface $planning;

    public function __construct()
    {
        // `convention_collection_on_concrete`: uninitialised is null, and the
        // first `add()` is a crash nobody sees until a fixture runs.
        $this->alerts = new ArrayCollection();
    }

    /**
     * @return Collection<int, PlanningEventAlertInterface>
     */
    public function getAlerts(): Collection
    {
        return $this->alerts;
    }

    /**
     * Sets both sides, and that is the point of the method existing.
     *
     * A alert computes its due time from its event, so one added through the
     * collection alone has no event to compute against and Doctrine writes a row
     * with no event_id.
     */
    public function addAlert(PlanningEventAlertInterface $alert): static
    {
        if (!$this->alerts->contains($alert)) {
            $this->alerts->add($alert);
            $alert->setEvent($this);
        }

        return $this;
    }

    public function removeAlert(PlanningEventAlertInterface $alert): static
    {
        $this->alerts->removeElement($alert);

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getStartAt(): DateTimeImmutable
    {
        return $this->startAt;
    }

    public function getEndAt(): DateTimeImmutable
    {
        return $this->endAt;
    }

    /**
     * Both ends at once, because neither is valid alone.
     *
     * Two setters would let an event exist, for one statement, with an end before
     * its start - and that is the state a month grid divides by. Refused here
     * rather than validated in a DTO as well: a fixture and a module pushing a
     * date both reach this and neither goes through a form.
     */
    public function setSpan(DateTimeImmutable $startAt, DateTimeImmutable $endAt): static
    {
        if ($endAt < $startAt) {
            throw new InvalidArgumentException('A planning event cannot end before it starts.');
        }

        $this->startAt = $startAt;
        $this->endAt = $endAt;

        // The alerts follow. `remindAt` is a stored column so the worker can
        // index it, which means moving the event has to move them too - and here
        // is the only place that knows the event moved.
        foreach ($this->alerts as $alert) {
            // Only the ones that follow the event. An alert pinned to a moment
            // was asked for at that moment, and moving it because the meeting
            // moved would take it away from the reader who chose it.
            if (!$alert->isRelative()) {
                continue;
            }

            // Re-setting the offset is what recomputes the stored due time. Not
            // a no-op, and not a trick: the offset is the input, `remindAt` is
            // derived, and the alert owns that derivation.
            $alert->setMinutesBefore((int) $alert->getMinutesBefore());
        }

        return $this;
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function setAllDay(bool $allDay): static
    {
        $this->allDay = $allDay;

        return $this;
    }

    public function getStatus(): PlanningEventStatusEnum
    {
        return $this->status;
    }

    public function setStatus(PlanningEventStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPlanning(): PlanningInterface
    {
        return $this->planning;
    }

    public function setPlanning(PlanningInterface $planning): static
    {
        $this->planning = $planning;

        return $this;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?int
    {
        return $this->sourceId;
    }

    public function getSourceLabel(): ?string
    {
        return $this->sourceLabel;
    }

    /**
     * All three together, or none of them.
     *
     * A type without an id points at nothing, and an id without a type points at
     * everything. The unique index on the pair only holds if they arrive
     * together, so this is where that is enforced rather than in whichever module
     * happens to be pushing.
     */
    public function setSource(?string $sourceType, ?int $sourceId, ?string $sourceLabel): static
    {
        if ((null === $sourceType) !== (null === $sourceId)) {
            throw new InvalidArgumentException('A planning event source needs both a type and an id, or neither.');
        }

        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->sourceLabel = $sourceLabel;

        return $this;
    }

    /**
     * Whether another module owns this event.
     *
     * What the screens ask, so none of them reads the provenance columns: an
     * event from a module has no Edit and no Delete, because it reflects a date
     * that lives somewhere else and the only useful gesture is to go there.
     */
    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): static
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    /**
     * Clamped rather than trusted, like the calendar's: a slot outside the
     * palette draws nothing at all, and an invisible event is worse than one in
     * the wrong colour. Null passes through, because null is a decision.
     */
    public function setColourSlot(?int $colourSlot): static
    {
        $this->colourSlot = null === $colourSlot
            ? null
            : max(1, min(AbstractPlanning::MAX_COLOUR_SLOT, $colourSlot));

        return $this;
    }

    /**
     * The colour to draw this event in.
     *
     * Resolved here rather than in each screen, because "its own, or its
     * calendar's" is one rule and three grids would each have to remember it.
     */
    public function getEffectiveColourSlot(): int
    {
        return $this->colourSlot ?? $this->getPlanning()->getColourSlot();
    }

    public function isFromModule(): bool
    {
        return null !== $this->sourceType && null !== $this->sourceId;
    }
}
