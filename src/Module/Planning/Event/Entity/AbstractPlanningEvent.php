<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
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

    #[ORM\ManyToOne(targetEntity: PlanningInterface::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PlanningInterface $planning;

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
    public function isFromModule(): bool
    {
        return null !== $this->sourceType && null !== $this->sourceId;
    }
}
