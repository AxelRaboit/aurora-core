<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Entity;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTerminationOriginEnum;
use Aurora\Module\Studio\Contract\Exception\ContractPdfAlreadyGeneratedException;
use Aurora\Module\Studio\Contract\Exception\FrozenContractIsImmutableException;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use DateTimeImmutable;

interface ContractInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getCustomer(): CustomerInterface;

    public function setCustomer(CustomerInterface $customer): static;

    public function getBodyVersion(): ?ContractTemplateVersionInterface;

    public function setBodyVersion(?ContractTemplateVersionInterface $bodyVersion): static;

    public function getAnnexVersion(): ?ContractTemplateVersionInterface;

    public function setAnnexVersion(?ContractTemplateVersionInterface $annexVersion): static;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getStatus(): ContractStatusEnum;

    public function setStatus(ContractStatusEnum $status): static;

    /** @return array<string, string> */
    public function getVariables(): array;

    /** @param array<string, string> $variables */
    public function setVariables(array $variables): static;

    /**
     * The blanks this one contract fills, keyed without the
     * `contract.custom.` prefix.
     *
     * @return array<string, string>
     */
    public function getCustomFields(): array;

    /** @param array<string, string> $customFields */
    public function setCustomFields(array $customFields): static;

    public function getAmountCents(): ?int;

    public function setAmountCents(?int $amountCents): static;

    public function getAmountCurrency(): ?CurrencyEnum;

    public function setAmountCurrency(?CurrencyEnum $amountCurrency): static;

    public function getEffectiveDate(): ?DateTimeImmutable;

    public function setEffectiveDate(?DateTimeImmutable $effectiveDate): static;

    public function getFrozenAt(): ?DateTimeImmutable;

    public function isFrozen(): bool;

    /** @return array<string, mixed> */
    public function getContentSnapshot(): array;

    public function getRenderedHtml(): ?string;

    public function getContentHash(): ?string;

    public function getHashAlgo(): ?string;

    public function getCanonicalVersion(): ?int;

    /**
     * Seals the document, once.
     *
     * @param array<string, mixed> $snapshot
     *
     * @throws FrozenContractIsImmutableException when already frozen
     */
    public function freeze(
        DateTimeImmutable $at,
        string $reference,
        array $snapshot,
        string $renderedHtml,
        string $contentHash,
        string $hashAlgo,
        int $canonicalVersion,
    ): static;

    public function getPdfPath(): ?string;

    public function getPdfHash(): ?string;

    public function getPdfGeneratedAt(): ?DateTimeImmutable;

    public function hasPdf(): bool;

    /**
     * Records the generated file, once.
     *
     * @throws ContractPdfAlreadyGeneratedException when one is already attached
     */
    public function attachPdf(string $path, string $hash, DateTimeImmutable $at): static;

    public function getRefusedAt(): ?DateTimeImmutable;

    public function getRefusalReason(): ?string;

    public function getRefusedFromIp(): ?string;

    public function getRefusedUserAgent(): ?string;

    public function isRefused(): bool;

    /** Records the refusal, its reason and its trace, and moves the status. */
    public function refuse(
        DateTimeImmutable $at,
        ?string $reason = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): static;

    /** Clears the current refusal, for a contract being sent again. */
    public function clearRefusal(): static;

    public function getReminderCount(): int;

    public function getLastReminderAt(): ?DateTimeImmutable;

    public function markReminded(DateTimeImmutable $at): static;

    public function getAmends(): ?self;

    /** Names the contract this one amends, and copies its reference. */
    public function setAmends(?self $amends): static;

    public function getAmendsReference(): ?string;

    public function isAmendment(): bool;

    public function getAmendmentRank(): ?int;

    public function setAmendmentRank(?int $rank): static;

    public function getTerminationNoticedAt(): ?DateTimeImmutable;

    public function getTerminationEffectiveAt(): ?DateTimeImmutable;

    public function getTerminationOrigin(): ?ContractTerminationOriginEnum;

    public function getTerminationReason(): ?string;

    public function isTerminated(): bool;

    /** Whether the termination has taken effect, as opposed to being due to. */
    public function isTerminationEffective(?DateTimeImmutable $on = null): bool;

    /** Records the end of the relationship, which is not the end of the document. */
    public function terminate(
        DateTimeImmutable $noticedAt,
        DateTimeImmutable $effectiveAt,
        ContractTerminationOriginEnum $origin,
        ?string $reason = null,
    ): static;

    /** The day the evidence stops being required, counted from the freeze. */
    public function retainedUntil(int $years): ?DateTimeImmutable;

    /** @throws FrozenContractIsImmutableException */
    public function assertEditable(): void;

    /**
     * @return array{locale: string, title: string, blocks: list<mixed>, baseVersionId: int|null, adaptedAt: string}|null
     */
    public function getAdaptedWording(ContractTemplateKindEnum $part): ?array;

    public function isAdapted(?ContractTemplateKindEnum $part = null): bool;

    /** @param list<mixed> $blocks */
    public function adaptWording(ContractTemplateKindEnum $part, string $title, array $blocks, DateTimeImmutable $at): static;

    public function resetWording(ContractTemplateKindEnum $part): static;

    public function adoptWordingFrom(ContractInterface $source): static;
}
