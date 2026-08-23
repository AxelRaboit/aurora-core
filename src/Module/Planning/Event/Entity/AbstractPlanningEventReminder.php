<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\MappedSuperclass]
abstract class AbstractPlanningEventReminder implements PlanningEventReminderInterface
{
    /**
     * The offsets a reminder may take, in minutes.
     *
     * A list and not a free number: every calendar offers a menu, because "37
     * minutes before" is a value nobody wants and a field everybody has to fill
     * in. Zero means "when it starts", which is the one people actually use for
     * a thing they are already at.
     */
    public const array OFFSETS = [0, 5, 10, 15, 30, 60, 120, 1440, 10080];

    public const int DEFAULT_OFFSET = 30;

    #[ORM\ManyToOne(targetEntity: PlanningEventInterface::class, inversedBy: 'reminders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PlanningEventInterface $event;

    #[ORM\Column(options: ['default' => self::DEFAULT_OFFSET])]
    protected int $minutesBefore = self::DEFAULT_OFFSET;

    /**
     * When this reminder is due, stored rather than computed.
     *
     * The worker asks "what is due now" every minute, and that has to be an
     * indexed comparison against a column. Computed from the event's start it
     * would be `start_at - interval` on every row of the table, once a minute,
     * for the rest of the application's life.
     *
     * The cost is that it has to be recomputed when the event moves, which is
     * why {@see PlanningEventReminderManager} owns both writes and no screen
     * sets this directly.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    protected DateTimeImmutable $remindAt;

    /**
     * Null until it fires, then never null again.
     *
     * A flag would have answered "has it fired", and this answers "when", which
     * is the question asked the day somebody says they got a reminder twice.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $sentAt = null;

    public function getEvent(): PlanningEventInterface
    {
        return $this->event;
    }

    public function setEvent(PlanningEventInterface $event): static
    {
        $this->event = $event;
        $this->recompute();

        return $this;
    }

    public function getMinutesBefore(): int
    {
        return $this->minutesBefore;
    }

    /**
     * Refuses an offset outside the menu.
     *
     * Not clamped, unlike a colour slot: a colour landing on the wrong step is
     * cosmetic, and a reminder landing at the wrong time is the whole feature
     * being wrong quietly.
     */
    public function setMinutesBefore(int $minutesBefore): static
    {
        if (!in_array($minutesBefore, self::OFFSETS, true)) {
            throw new InvalidArgumentException(sprintf('%d is not one of the reminder offsets.', $minutesBefore));
        }

        $this->minutesBefore = $minutesBefore;
        $this->recompute();

        return $this;
    }

    public function getRemindAt(): DateTimeImmutable
    {
        return $this->remindAt;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSent(DateTimeImmutable $at): static
    {
        $this->sentAt = $at;

        return $this;
    }

    /**
     * Whether it still has to fire.
     *
     * What the worker filters on together with the due time, so a reminder that
     * has already gone out cannot be picked up by a second worker or by a retry.
     */
    public function isPending(): bool
    {
        return !$this->sentAt instanceof DateTimeImmutable;
    }

    /**
     * Kept in step with the event and the offset, whichever of the two moved.
     *
     * Guarded because the event is set before the offset on a fresh reminder and
     * `$event` is not initialised yet on the first call.
     */
    protected function recompute(): void
    {
        if (!isset($this->event)) {
            return;
        }

        $this->remindAt = $this->event->getStartAt()->modify(sprintf('-%d minutes', $this->minutesBefore));
    }
}
