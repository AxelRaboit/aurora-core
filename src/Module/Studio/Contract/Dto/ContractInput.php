<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What somebody chooses when preparing a contract.
 *
 * Templates rather than versions, because that is the choice a person makes:
 * "the monthly contract", not "version 3 of the monthly contract". The manager
 * turns each into the version published today and stores that, so the draft is
 * pinned from the moment it is created - a version published tomorrow does not
 * silently change a contract somebody is in the middle of preparing.
 */
class ContractInput implements ContractInputInterface
{
    public function __construct(
        #[Assert\NotNull(message: 'suite.studio.contracts.errors.customer_required')]
        #[Assert\Positive(message: 'suite.studio.contracts.errors.customer_required')]
        public readonly ?int $customerId = null,
        #[Assert\NotNull(message: 'suite.studio.contracts.errors.body_required')]
        #[Assert\Positive(message: 'suite.studio.contracts.errors.body_required')]
        public readonly ?int $bodyTemplateId = null,
        public readonly ?int $annexTemplateId = null,
        #[Assert\NotBlank(message: 'suite.studio.contracts.errors.locale_required')]
        #[Assert\Length(max: 10)]
        public readonly string $locale = 'fr',
        #[Assert\PositiveOrZero(message: 'suite.studio.contracts.errors.amount_invalid')]
        public readonly ?int $amountCents = null,
        #[Assert\Length(max: 3)]
        public readonly ?string $amountCurrency = null,
        // A date the browser sends as Y-m-d. Kept as a string here and parsed
        // by the manager, so an unparseable one is a field error rather than a
        // type error thrown out of a constructor.
        #[Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/', message: 'suite.studio.contracts.errors.effective_date_invalid')]
        public readonly ?string $effectiveDate = null,
        // The blanks the chosen trames ask for, keyed without the
        // `contract.custom.` prefix. Which ones are required is the wording's
        // business, checked at the freeze; what is checked here is only that a
        // key looks like a token, because a key that cannot appear in a trame
        // can only be a mistake.
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\Length(max: 500, maxMessage: 'suite.studio.contracts.errors.custom_field_too_long'),
        ])]
        public readonly array $customFields = [],
        // The contract this one amends, when it is an amendment. Optional, and
        // the discriminator: a contract with a parent is an amendment, so
        // there is no second field saying so that could disagree with it.
        #[Assert\Positive(message: 'suite.studio.contracts.errors.amends_invalid')]
        public readonly ?int $amendsId = null,
    ) {}

    public function getAmendsId(): ?int
    {
        return $this->amendsId;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function getBodyTemplateId(): ?int
    {
        return $this->bodyTemplateId;
    }

    public function getAnnexTemplateId(): ?int
    {
        return $this->annexTemplateId;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getAmountCents(): ?int
    {
        return $this->amountCents;
    }

    public function getAmountCurrency(): ?string
    {
        return $this->amountCurrency;
    }

    public function getCustomFields(): array
    {
        return $this->customFields;
    }

    public function getEffectiveDate(): ?string
    {
        return $this->effectiveDate;
    }
}
