<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Dto;

use Aurora\Module\Planning\Planning\Entity\AbstractPlanning;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Symfony\Component\Validator\Constraints as Assert;

class PlanningInput implements PlanningInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.plannings.errors.name_required')]
        #[Assert\Length(max: 150)]
        public readonly string $name = '',
        public readonly ?string $description = null,
        #[Assert\Range(notInRangeMessage: 'backend.plannings.errors.colour_out_of_range', min: 1, max: AbstractPlanning::MAX_COLOUR_SLOT)]
        public readonly int $colourSlot = AbstractPlanning::DEFAULT_COLOUR_SLOT,
        /**
         * Validated against the zones PHP knows rather than a list of our own:
         * a calendar cutting its days in a zone the runtime cannot resolve puts
         * every all-day event on the wrong day.
         */
        #[Assert\Timezone(message: 'backend.plannings.errors.timezone_unknown')]
        public readonly string $timezone = 'Europe/Paris',
        public readonly PlanningVisibilityEnum $visibility = PlanningVisibilityEnum::Private,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getColourSlot(): int
    {
        return $this->colourSlot;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getVisibility(): PlanningVisibilityEnum
    {
        return $this->visibility;
    }
}
