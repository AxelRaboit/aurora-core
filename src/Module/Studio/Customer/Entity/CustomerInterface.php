<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Entity;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Studio\Customer\Enum\CustomerSourceEnum;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;
use DateTimeImmutable;

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

    public function getContractualEmail(): ?string;

    public function setContractualEmail(?string $contractualEmail): static;

    public function getPhone(): ?string;

    public function setPhone(?string $phone): static;

    public function getLandline(): ?string;

    public function setLandline(?string $landline): static;

    public function getSiren(): ?string;

    public function setSiren(?string $siren): static;

    /** @return list<array{label: string, url: string}> */
    public function getLinks(): array;

    /** @param list<array{label: string, url: string}> $links */
    public function setLinks(array $links): static;

    public function getInformationNotes(): ?string;

    public function setInformationNotes(?string $informationNotes): static;

    /**
     * The representative's full name, or null when neither half is known.
     *
     * Read by the contract layer, which prints "représenté par …" and has no
     * business deciding how a name is assembled from its parts.
     */
    public function getRepresentativeFullName(): ?string;

    public function getPipelineStage(): ?PipelineStageInterface;

    public function setPipelineStage(?PipelineStageInterface $pipelineStage): static;

    public function getPipelinePosition(): int;

    public function setPipelinePosition(int $pipelinePosition): static;

    public function getPipelineStageChangedAt(): ?DateTimeImmutable;

    public function setPipelineStageChangedAt(?DateTimeImmutable $pipelineStageChangedAt): static;

    public function getNextFollowUpOn(): ?DateTimeImmutable;

    public function setNextFollowUpOn(?DateTimeImmutable $nextFollowUpOn): static;

    public function getFollowUpNote(): ?string;

    public function setFollowUpNote(?string $followUpNote): static;

    public function getFollowUpNotifiedOn(): ?DateTimeImmutable;

    public function setFollowUpNotifiedOn(?DateTimeImmutable $followUpNotifiedOn): static;

    public function getSource(): ?CustomerSourceEnum;

    public function setSource(?CustomerSourceEnum $source): static;

    public function getSourceReference(): ?string;

    public function setSourceReference(?string $sourceReference): static;

    public function getEstimatedValueCents(): ?int;

    public function setEstimatedValueCents(?int $estimatedValueCents): static;

    public function getEstimatedValueCurrency(): ?CurrencyEnum;

    public function setEstimatedValueCurrency(?CurrencyEnum $estimatedValueCurrency): static;

    public function getLostReason(): ?string;

    public function setLostReason(?string $lostReason): static;
}
