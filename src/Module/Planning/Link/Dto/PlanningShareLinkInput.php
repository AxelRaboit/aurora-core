<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Dto;

use Aurora\Module\Planning\Link\Entity\PlanningShareLinkModeEnum;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A request to open an address onto one or more calendars.
 *
 * The validation here is the whole of it, and each rule is a way somebody gets
 * hurt rather than a way the data looks wrong.
 */
final readonly class PlanningShareLinkInput
{
    /**
     * @param list<int> $calendarIds
     */
    public function __construct(
        /**
         * At least one, or the link reaches nothing and the person who made it
         * finds out when their guest says the page is empty.
         */
        #[Assert\Count(min: 1, minMessage: 'backend.plannings.links.errors.calendars_required')]
        public array $calendarIds,
        /**
         * Required, because the question asked later is "which one did I give to
         * the studio" and a list of unnamed tokens cannot be revoked with any
         * confidence.
         */
        #[Assert\NotBlank(message: 'backend.plannings.links.errors.label_required')]
        #[Assert\Length(max: 120, maxMessage: 'backend.plannings.links.errors.label_too_long')]
        public string $label,
        public PlanningShareLinkModeEnum $mode = PlanningShareLinkModeEnum::Web,
        /**
         * Null means it never expires, which is what a feed subscription needs and
         * what a guest link should almost never be.
         */
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    /** @return list<int> */
    public function getCalendarIds(): array
    {
        return $this->calendarIds;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getMode(): PlanningShareLinkModeEnum
    {
        return $this->mode;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
