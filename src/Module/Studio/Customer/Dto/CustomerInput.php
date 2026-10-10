<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Module\Studio\Customer\Enum\CustomerSourceEnum;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Customer\Validator\Siren;
use Aurora\Module\Studio\Customer\Validator\Siret;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function array_map;
use function str_starts_with;

/**
 * A customer's whole sheet, as their page fills it in.
 *
 * **A single input for the whole sheet.** There used to be two: this one,
 * without SIREN, landline, links or notes, and the one of a space's
 * Informations tab, without capital, RCS, VAT or representative. Each could
 * only write its own columns, and the SIREN could only be entered from a
 * space. The customer's page now carries every field, and it is the only
 * write path.
 */
class CustomerInput implements CustomerInputInterface
{
    /** @param list<CustomerLinkInput> $links */
    public function __construct(
        #[Assert\NotBlank(message: 'suite.studio.customers.errors.legal_name_required')]
        #[Assert\Length(max: 180, maxMessage: 'suite.studio.customers.errors.legal_name_too_long')]
        public readonly string $legalName = '',
        #[Assert\Length(max: 60)]
        public readonly ?string $legalForm = null,
        // Zero is a real answer (an association has no capital), so the floor
        // is zero rather than one - and negative capital is not a thing.
        #[Assert\PositiveOrZero(message: 'suite.studio.customers.errors.share_capital_invalid')]
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
        // Required for a customer and not for a prospect, so the rule covers
        // the pair: the Manager holds it, it is the one that sees the status.
        #[Assert\Email(message: 'suite.studio.customers.errors.contractual_email_invalid')]
        #[Assert\Length(max: 180)]
        public readonly ?string $contractualEmail = null,
        #[Assert\Length(max: 30, maxMessage: 'suite.studio.customers.errors.phone_too_long')]
        public readonly ?string $phone = null,
        public readonly CustomerStatusEnum $status = CustomerStatusEnum::Prospect,
        #[Siren]
        public readonly ?string $siren = null,
        #[Assert\Length(max: 30, maxMessage: 'suite.studio.customers.errors.phone_too_long')]
        public readonly ?string $landline = null,
        /**
         * `Valid` is what makes validation go down into each row. Without it,
         * an array of objects is traversed without their own constraints
         * being read, and an invalid address would pass.
         *
         * @var list<CustomerLinkInput>
         */
        #[Assert\Valid]
        #[Assert\Count(max: 30, maxMessage: 'suite.studio.customers.errors.links_too_many')]
        public readonly array $links = [],
        #[Assert\Length(max: 5000, maxMessage: 'suite.studio.customers.errors.notes_too_long')]
        public readonly ?string $informationNotes = null,
        // The follow-up: when, and about what. Any date is accepted, a past
        // one included - it is simply due already.
        public readonly ?DateTimeImmutable $nextFollowUpOn = null,
        #[Assert\Length(max: 255, maxMessage: 'suite.studio.customers.errors.follow_up_note_too_long')]
        public readonly ?string $followUpNote = null,
        public readonly ?CustomerSourceEnum $source = null,
        #[Assert\PositiveOrZero(message: 'suite.studio.customers.errors.estimated_value_invalid')]
        public readonly ?int $estimatedValueCents = null,
        public readonly ?CurrencyEnum $estimatedValueCurrency = null,
        #[Assert\Length(max: 255, maxMessage: 'suite.studio.customers.errors.lost_reason_too_long')]
        public readonly ?string $lostReason = null,
    ) {}

    /**
     * Both numbers must refer to the same company.
     *
     * A SIRET is the SIREN followed by the establishment's five digits. When
     * both are entered and do not agree, one of them is wrong and nothing
     * says which. The error goes on the SIREN: it is the field corrected most
     * often, the SIRET being copied from a document.
     *
     * Each keeps its own check digit elsewhere; this does not replace
     * {@see Siret} or {@see Siren}, it checks that they agree.
     */
    #[Assert\Callback]
    public function validateNumbersAgree(ExecutionContextInterface $context): void
    {
        if (null === $this->siret || null === $this->siren) {
            return;
        }

        if (str_starts_with($this->siret, $this->siren)) {
            return;
        }

        $context->buildViolation('suite.studio.customers.errors.siren_mismatch')
            ->atPath('siren')
            ->addViolation();
    }

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

    public function getSiren(): ?string
    {
        return $this->siren;
    }

    public function getLandline(): ?string
    {
        return $this->landline;
    }

    /**
     * The links, in the shape the column stores.
     *
     * The conversion happens here and not in the manager: the input is what
     * knows the shape of its own objects, and the entity must only see a list
     * of pairs.
     *
     * @return list<array{label: string, url: string}>
     */
    public function getLinks(): array
    {
        return array_map(
            static fn (CustomerLinkInput $link): array => ['label' => $link->label, 'url' => $link->url],
            $this->links,
        );
    }

    public function getInformationNotes(): ?string
    {
        return $this->informationNotes;
    }

    public function getNextFollowUpOn(): ?DateTimeImmutable
    {
        return $this->nextFollowUpOn;
    }

    public function getFollowUpNote(): ?string
    {
        return $this->followUpNote;
    }

    public function getSource(): ?CustomerSourceEnum
    {
        return $this->source;
    }

    public function getEstimatedValueCents(): ?int
    {
        return $this->estimatedValueCents;
    }

    public function getEstimatedValueCurrency(): ?CurrencyEnum
    {
        return $this->estimatedValueCurrency;
    }

    public function getLostReason(): ?string
    {
        return $this->lostReason;
    }
}
