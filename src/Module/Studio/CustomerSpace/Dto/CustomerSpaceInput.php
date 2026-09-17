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
        // Neither required nor checked here: a space names an existing company
        // or opens a prospect for one, and which of the two is a rule about the
        // pair. The Manager owns it, because it is also the only thing holding
        // the repository that says whether the id resolves.
        public readonly ?int $customerId = null,
        /**
         * A company nobody has a record for yet.
         *
         * Filled instead of `customerId` when the space is opened for somebody
         * you are only starting to work with. The Manager creates the customer
         * as a prospect and links it, so the space has a real company behind it
         * from the first minute and nothing downstream has to cope with a space
         * that belongs to nobody.
         */
        #[Assert\Length(max: 180, maxMessage: 'backend.studio.customers.errors.legal_name_too_long')]
        public readonly ?string $prospectName = null,
        #[Assert\Email(message: 'backend.studio.customers.errors.contractual_email_invalid')]
        #[Assert\Length(max: 180)]
        public readonly ?string $prospectEmail = null,
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

    public function getProspectName(): ?string
    {
        return $this->prospectName;
    }

    public function getProspectEmail(): ?string
    {
        return $this->prospectEmail;
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
