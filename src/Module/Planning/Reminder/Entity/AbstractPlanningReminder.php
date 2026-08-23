<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Planning\Event\Enum\PlanningAlertChannelEnum;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A thing to do by a date, as opposed to a thing that happens at one.
 *
 * The distinction is the whole reason this is not an event with a flag. An event
 * has two ends and occupies them; a reminder has one moment and a state. An event
 * at 14:00 is over at 15:00 whatever you do, and a reminder due at 14:00 is still
 * there at 18:00 until somebody ticks it - which is why the grid draws it with a
 * checkbox and keeps drawing it after its time.
 *
 * Modelled on Apple's split rather than on a single `type` column, because almost
 * every field would then be nullable-depending-on-type: `end_at` means nothing on
 * a reminder, `completed_at` means nothing on an event, and every query would
 * carry a `WHERE type =` that the table structure should have made unnecessary.
 *
 * `MappedSuperclass` plus an interface, per the extensibility convention: a
 * consumer project can replace the concrete class through
 * `resolve_target_entities` without touching this.
 */
#[ORM\MappedSuperclass]
// Without this the trait's PrePersist never runs and `created_at` is written
// null, which the column refuses - the first save fails rather than saving
// something wrong, which is the good version of this mistake.
#[ORM\HasLifecycleCallbacks]
abstract class AbstractPlanningReminder implements PlanningReminderInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: PlanningInterface::class, inversedBy: 'reminders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PlanningInterface $planning;

    #[ORM\Column(length: 255)]
    protected string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $notes = null;

    /**
     * When it is due. Always set, unlike Apple, which allows a reminder with no
     * date at all.
     *
     * A dateless reminder cannot be drawn on a calendar, and this module is a
     * calendar - a list of undated intentions is a different product with a
     * different screen. Requiring the date keeps every reminder reachable from
     * the grid it lives in.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    protected DateTimeImmutable $dueAt;

    /**
     * True when only the day matters.
     *
     * The time is still stored - it is the day's start in the calendar's zone -
     * so the same window query finds it. This only says whether to draw a clock
     * beside it, and whether being "late" means anything before the day is over.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $allDay = false;

    /**
     * When it was ticked, or null.
     *
     * A timestamp and not a boolean, for the same reason the alerts have one: the
     * question worth answering later is "when was this done", and a flag can only
     * answer "was it". It also makes "completed today" a query rather than a
     * second column.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $completedAt = null;

    /**
     * When the worker told somebody, or null.
     *
     * Reminders notify at their due time rather than at an offset before it,
     * which is what a reminder is: an event needs warning because you have to be
     * somewhere, and a reminder is the thing itself arriving. Offsets can be added
     * later without moving this column.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $notifiedAt = null;

    /**
     * How this reminder announces itself.
     *
     * On the reminder rather than on an alert row, because a reminder has none: it
     * announces itself at its due time, so the choice belongs to the thing that
     * announces.
     */
    #[ORM\Column(length: 20, enumType: PlanningAlertChannelEnum::class, options: ['default' => PlanningAlertChannelEnum::Notification->value])]
    protected PlanningAlertChannelEnum $channel = PlanningAlertChannelEnum::Notification;

    abstract public function getId(): ?int;

    public function getPlanning(): PlanningInterface
    {
        return $this->planning;
    }

    public function setPlanning(PlanningInterface $planning): static
    {
        $this->planning = $planning;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getDueAt(): DateTimeImmutable
    {
        return $this->dueAt;
    }

    /**
     * Moving a reminder clears the fact that it was announced.
     *
     * Otherwise pushing something to next week means never hearing about it
     * again: the row is already stamped as notified, and nothing would ever
     * announce the new date. Deferring is the most common thing anybody does to
     * a reminder, so getting this wrong makes the feature quietly useless.
     */
    public function setDueAt(DateTimeImmutable $dueAt): static
    {
        if (!isset($this->dueAt) || $dueAt->getTimestamp() !== $this->dueAt->getTimestamp()) {
            $this->notifiedAt = null;
        }

        $this->dueAt = $dueAt;

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

    public function getChannel(): PlanningAlertChannelEnum
    {
        return $this->channel;
    }

    public function setChannel(PlanningAlertChannelEnum $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isCompleted(): bool
    {
        return $this->completedAt instanceof DateTimeImmutable;
    }

    public function complete(DateTimeImmutable $at): static
    {
        $this->completedAt = $at;

        return $this;
    }

    public function reopen(): static
    {
        $this->completedAt = null;

        return $this;
    }

    public function getNotifiedAt(): ?DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function markNotified(DateTimeImmutable $at): static
    {
        $this->notifiedAt = $at;

        return $this;
    }
}
