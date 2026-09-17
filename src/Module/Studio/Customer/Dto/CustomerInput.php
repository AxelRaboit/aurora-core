<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Customer\Validator\Siret;
use Symfony\Component\Validator\Constraints as Assert;

class CustomerInput implements CustomerInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.customers.errors.legal_name_required')]
        #[Assert\Length(max: 180, maxMessage: 'backend.studio.customers.errors.legal_name_too_long')]
        public readonly string $legalName = '',
        #[Assert\Length(max: 60)]
        public readonly ?string $legalForm = null,
        // Zero is a real answer (an association has no capital), so the floor
        // is zero rather than one - and negative capital is not a thing.
        #[Assert\PositiveOrZero(message: 'backend.studio.customers.errors.share_capital_invalid')]
        public readonly ?int $shareCapitalCents = null,
        public readonly ?CurrencyEnum $shareCapitalCurrency = null,
        #[Assert\Length(max: 500)]
        public readonly ?string $registeredOffice = null,
        #[Siret]
        public readonly ?string $siret = null,
        #[Assert\Length(max: 120)]
        public readonly ?string $tradeRegister = null,
        #[Assert\Length(max: 30)]
        public readonly ?string $vatNumber = null,
        #[Assert\Length(max: 180)]
        public readonly ?string $activitySector = null,
        #[Assert\Length(max: 100)]
        public readonly ?string $representativeFirstName = null,
        #[Assert\Length(max: 100)]
        public readonly ?string $representativeLastName = null,
        #[Assert\Length(max: 120)]
        public readonly ?string $representativeRole = null,
        // Requis d'un client et pas d'un prospect, donc la regle porte sur la
        // paire : le Manager la tient, c'est lui qui voit le statut.
        #[Assert\Email(message: 'backend.studio.customers.errors.contractual_email_invalid')]
        #[Assert\Length(max: 180)]
        public readonly ?string $contractualEmail = null,
        #[Assert\Length(max: 30)]
        public readonly ?string $phone = null,
        public readonly ?int $userId = null,
        public readonly CustomerStatusEnum $status = CustomerStatusEnum::Prospect,
    ) {}

    public function getLegalName(): string
    {
        return $this->legalName;
    }

    public function getStatus(): CustomerStatusEnum
    {
        return $this->status;
    }

    public function getLegalForm(): ?string
    {
        return $this->legalForm;
    }

    public function getShareCapitalCents(): ?int
    {
        return $this->shareCapitalCents;
    }

    public function getShareCapitalCurrency(): ?CurrencyEnum
    {
        return $this->shareCapitalCurrency;
    }

    public function getRegisteredOffice(): ?string
    {
        return $this->registeredOffice;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function getTradeRegister(): ?string
    {
        return $this->tradeRegister;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function getActivitySector(): ?string
    {
        return $this->activitySector;
    }

    public function getRepresentativeFirstName(): ?string
    {
        return $this->representativeFirstName;
    }

    public function getRepresentativeLastName(): ?string
    {
        return $this->representativeLastName;
    }

    public function getRepresentativeRole(): ?string
    {
        return $this->representativeRole;
    }

    public function getContractualEmail(): ?string
    {
        return $this->contractualEmail;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }
}
