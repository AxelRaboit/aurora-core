<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Module\Studio\Customer\Enum\CustomerSourceEnum;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use DateTimeImmutable;

interface CustomerInputInterface
{
    public function getLegalName(): string;

    public function getStatus(): CustomerStatusEnum;

    public function getLegalForm(): ?string;

    public function getShareCapitalCents(): ?int;

    public function getShareCapitalCurrency(): ?CurrencyEnum;

    public function getRegisteredOffice(): ?string;

    public function getSiret(): ?string;

    public function getTradeRegister(): ?string;

    public function getVatNumber(): ?string;

    public function getActivitySector(): ?string;

    public function getRepresentativeFirstName(): ?string;

    public function getRepresentativeLastName(): ?string;

    public function getRepresentativeRole(): ?string;

    public function getContractualEmail(): ?string;

    public function getPhone(): ?string;

    public function getSiren(): ?string;

    public function getLandline(): ?string;

    /** @return list<array{label: string, url: string}> */
    public function getLinks(): array;

    public function getInformationNotes(): ?string;

    public function getNextFollowUpOn(): ?DateTimeImmutable;

    public function getFollowUpNote(): ?string;

    public function getSource(): ?CustomerSourceEnum;

    /** An estimate to weigh the pipeline with, never an amount invoiced. */
    public function getEstimatedValueCents(): ?int;

    public function getEstimatedValueCurrency(): ?CurrencyEnum;

    public function getLostReason(): ?string;
}
