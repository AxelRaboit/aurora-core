<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Customer\Validator\Siren;
use Aurora\Module\Studio\Customer\Validator\Siret;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function array_map;
use function str_starts_with;

/**
 * La fiche d'un client, entiere, telle que sa page la remplit.
 *
 * **Une seule saisie pour toute la fiche.** Il y en avait deux : celle-ci, sans
 * SIREN, fixe, liens ni notes, et celle de l'onglet Informations d'un espace,
 * sans capital, RCS, TVA ni representant. Chacune ne pouvait ecrire que ses
 * colonnes, et le SIREN ne se saisissait que depuis un espace. La page du
 * client porte maintenant tous les champs, et c'est le seul chemin d'ecriture.
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
        // Requis d'un client et pas d'un prospect, donc la regle porte sur la
        // paire : le Manager la tient, c'est lui qui voit le statut.
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
         * `Valid` est ce qui fait descendre la validation dans chaque ligne.
         * Sans lui, un tableau d'objets est traverse sans que leurs propres
         * contraintes soient lues, et une adresse invalide passerait.
         *
         * @var list<CustomerLinkInput>
         */
        #[Assert\Valid]
        #[Assert\Count(max: 30, maxMessage: 'suite.studio.customers.errors.links_too_many')]
        public readonly array $links = [],
        #[Assert\Length(max: 5000, maxMessage: 'suite.studio.customers.errors.notes_too_long')]
        public readonly ?string $informationNotes = null,
    ) {}

    /**
     * Les deux numeros doivent parler de la meme entreprise.
     *
     * Un SIRET est le SIREN suivi des cinq chiffres de l'etablissement. Quand
     * les deux sont saisis et ne s'accordent pas, l'un des deux est faux et
     * rien ne dit lequel. L'erreur se pose sur le SIREN : c'est le champ
     * qu'on corrige le plus souvent, le SIRET se recopiant d'un document.
     *
     * Chacun garde sa propre cle de controle par ailleurs ; ceci ne remplace
     * pas {@see Siret} ni {@see Siren}, cela verifie leur accord.
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
     * Les liens, sous la forme que la colonne stocke.
     *
     * La conversion se fait ici et pas dans le gestionnaire : la saisie est ce
     * qui connait la forme de ses propres objets, et l'entite ne doit voir
     * qu'une liste de couples.
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
}
