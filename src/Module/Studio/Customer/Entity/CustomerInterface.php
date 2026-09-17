<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Entity;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;

interface CustomerInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getLegalName(): string;

    public function setLegalName(string $legalName): static;

    public function getLegalForm(): ?string;

    public function setLegalForm(?string $legalForm): static;

    public function getShareCapitalCents(): ?int;

    public function setShareCapitalCents(?int $shareCapitalCents): static;

    public function getShareCapitalCurrency(): ?CurrencyEnum;

    public function setShareCapitalCurrency(?CurrencyEnum $shareCapitalCurrency): static;

    public function getRegisteredOffice(): ?string;

    public function setRegisteredOffice(?string $registeredOffice): static;

    public function getSiret(): ?string;

    public function setSiret(?string $siret): static;

    public function getTradeRegister(): ?string;

    public function setTradeRegister(?string $tradeRegister): static;

    public function getVatNumber(): ?string;

    public function setVatNumber(?string $vatNumber): static;

    public function getActivitySector(): ?string;

    public function setActivitySector(?string $activitySector): static;

    public function getRepresentativeFirstName(): ?string;

    public function setRepresentativeFirstName(?string $representativeFirstName): static;

    public function getRepresentativeLastName(): ?string;

    public function setRepresentativeLastName(?string $representativeLastName): static;

    public function getRepresentativeRole(): ?string;

    public function setRepresentativeRole(?string $representativeRole): static;

    public function getStatus(): CustomerStatusEnum;

    public function setStatus(CustomerStatusEnum $status): static;

    public function isProspect(): bool;

    public function getContractualEmail(): string;

    public function setContractualEmail(string $contractualEmail): static;

    public function getPhone(): ?string;

    public function setPhone(?string $phone): static;

    public function getUser(): ?CoreUserInterface;

    public function setUser(?CoreUserInterface $user): static;

    /**
     * The representative's full name, or null when neither half is known.
     *
     * Read by the contract layer, which prints "représenté par …" and has no
     * business deciding how a name is assembled from its parts.
     */
    public function getRepresentativeFullName(): ?string;
}
