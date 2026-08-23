<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Dto;

use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Planning\Entity\AbstractPlanning;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PlanningEventInput implements PlanningEventInputInterface
{
    public function __construct(
        #[Assert\Positive(message: 'backend.plannings.events.errors.calendar_required')]
        public readonly int $planningId = 0,
        #[Assert\NotBlank(message: 'backend.plannings.events.errors.title_required')]
        #[Assert\Length(max: 255)]
        public readonly string $title = '',
        public readonly ?string $description = null,
        #[Assert\Length(max: 255)]
        public readonly ?string $location = null,
        public readonly ?DateTimeImmutable $startAt = null,
        public readonly ?DateTimeImmutable $endAt = null,
        public readonly bool $allDay = false,
        public readonly PlanningEventStatusEnum $status = PlanningEventStatusEnum::Confirmed,
        /** A slot of the event's own, or null to follow the calendar. */
        #[Assert\Range(notInRangeMessage: 'backend.plannings.errors.colour_out_of_range', min: 1, max: AbstractPlanning::MAX_COLOUR_SLOT)]
        public readonly ?int $colourSlot = null,
        /**
         * The alerts, each either an offset in minutes or a pinned moment.
         *
         * The whole set arrives on every save: the form shows every alert at
         * once, so a diff computed here would only be a diff the client already
         * made.
         *
         * @var list<array{minutes: int|null, at: DateTimeImmutable|null}>
         */
        public readonly array $alerts = [],
    ) {}

    public function getPlanningId(): int
    {
        return $this->planningId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    /**
     * Both ends are nullable on the DTO and not on the way out, because a
     * missing date is a validation failure rather than a default: an event
     * silently landing on today is worse than a form saying it needs a date.
     */
    #[Assert\Callback]
    public function validateSpan(ExecutionContextInterface $context): void
    {
        if (!$this->startAt instanceof DateTimeImmutable) {
            $context->buildViolation('backend.plannings.events.errors.start_required')
                ->atPath('startAt')
                ->addViolation();

            return;
        }

        if (!$this->endAt instanceof DateTimeImmutable) {
            $context->buildViolation('backend.plannings.events.errors.end_required')
                ->atPath('endAt')
                ->addViolation();

            return;
        }

        // Said here as well as on the entity, and the two are not redundant: the
        // entity refuses it so a fixture cannot write it, and this names the
        // field so a form can point at it.
        if ($this->endAt < $this->startAt) {
            $context->buildViolation('backend.plannings.events.errors.end_before_start')
                ->atPath('endAt')
                ->addViolation();
        }
    }

    public function getStartAt(): DateTimeImmutable
    {
        return $this->startAt ?? new DateTimeImmutable();
    }

    public function getEndAt(): DateTimeImmutable
    {
        return $this->endAt ?? $this->getStartAt();
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function getStatus(): PlanningEventStatusEnum
    {
        return $this->status;
    }

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    /** @return list<array{minutes: int|null, at: DateTimeImmutable|null}> */
    public function getAlerts(): array
    {
        return $this->alerts;
    }
}
