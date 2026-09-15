<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Dto;

use Aurora\Module\Studio\CustomerSpace\Entity\AbstractCustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Symfony\Component\Validator\Constraints as Assert;

class CustomerSpaceInput implements CustomerSpaceInputInterface
{
    /** @param list<array{userId: int, role: string}> $members */
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.spaces.errors.name_required')]
        #[Assert\Length(max: 150, maxMessage: 'backend.studio.spaces.errors.name_too_long')]
        public readonly string $name = '',
        #[Assert\Length(max: 2000)]
        public readonly ?string $description = null,
        // Checked as "present" rather than "exists": the Manager resolves the
        // id and reports an unknown one, because only it holds the repository.
        #[Assert\NotNull(message: 'backend.studio.spaces.errors.customer_required')]
        #[Assert\Positive(message: 'backend.studio.spaces.errors.customer_required')]
        public readonly ?int $customerId = null,
        public readonly CustomerSpaceStatusEnum $status = CustomerSpaceStatusEnum::Active,
        #[Assert\Range(min: 1, max: AbstractCustomerSpace::MAX_COLOUR_SLOT)]
        public readonly ?int $colourSlot = null,
        // A zone the browser did not send falls back on the entity's own
        // default, so this is never empty and never needs a NotBlank.
        #[Assert\Timezone(message: 'backend.studio.spaces.errors.timezone_invalid')]
        public readonly string $timezone = 'Europe/Paris',
        public readonly array $members = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function getStatus(): CustomerSpaceStatusEnum
    {
        return $this->status;
    }

    public function getColourSlot(): ?int
    {
        return $this->colourSlot;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /** @return list<array{userId: int, role: string}> */
    public function getMembers(): array
    {
        return $this->members;
    }
}
