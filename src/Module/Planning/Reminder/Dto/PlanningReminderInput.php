<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Dto;

use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PlanningReminderInput implements PlanningReminderInputInterface
{
    public function __construct(
        #[Assert\Positive(message: 'backend.plannings.events.errors.calendar_required')]
        public readonly int $planningId = 0,
        #[Assert\NotBlank(message: 'backend.plannings.reminders.errors.title_required')]
        #[Assert\Length(max: 255)]
        public readonly string $title = '',
        public readonly ?string $notes = null,
        public readonly ?DateTimeImmutable $dueAt = null,
        public readonly bool $allDay = false,
        /**
         * Whether it arrives already ticked.
         *
         * On the form this is the checkbox the reader can tick while editing, and
         * it is a plain field rather than a separate endpoint because editing a
         * reminder and finishing it are the same gesture as often as not. The
         * grid's checkbox has its own route, which only touches this.
         */
        public readonly bool $completed = false,
    ) {}

    public function getPlanningId(): int
    {
        return $this->planningId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * A reminder needs a date, and an absent one is a validation failure rather
     * than a default.
     *
     * Same reasoning as the event's two ends: silently landing on today reports
     * success for something the reader did not ask for.
     */
    #[Assert\Callback]
    public function validateDueAt(ExecutionContextInterface $context): void
    {
        if (!$this->dueAt instanceof DateTimeImmutable) {
            $context->buildViolation('backend.plannings.reminders.errors.due_required')
                ->atPath('dueAt')
                ->addViolation();
        }
    }

    public function getDueAt(): DateTimeImmutable
    {
        return $this->dueAt ?? new DateTimeImmutable();
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }
}
