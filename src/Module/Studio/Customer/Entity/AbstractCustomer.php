<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Entity;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function implode;
use function mb_trim;

/**
 * The other party: who a contract is signed with, and who an invoice is
 * addressed to.
 *
 * The columns are not a generic address book. They are the identity block a
 * French service contract opens with - raison sociale, forme juridique,
 * capital, siège, SIRET, RCS, TVA, représentant - because that block is what
 * has to be filled in before anything can be signed, and retyping it per
 * contract is how two contracts end up disagreeing about the same company.
 *
 * Only two fields are required: the legal name, which is what the row is
 * called everywhere, and the contractual email, which is where the signing
 * link goes. Everything else is nullable on purpose - a customer often exists
 * as a prospect weeks before anyone has their SIRET, and a form that refuses
 * to save until every legal field is known is a form people keep in a
 * spreadsheet instead. The contract layer is where a missing field becomes an
 * error, because that is the moment it actually matters.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractCustomer implements CustomerInterface
{
    use TimestampableTrait;

    /** Raison sociale. The name every list, picker and contract shows. */
    #[ORM\Column(length: 180)]
    protected string $legalName;

    /**
     * Whether this company has engaged yet.
     *
     * Defaults to a prospect, because that is what a record is at the moment it
     * is created: somebody you have opened a space for. Everything that makes a
     * client - the SIRET, the legal form, the registered office - is filled in
     * later, and flipping this is what says it has been.
     */
    #[ORM\Column(length: 20, enumType: CustomerStatusEnum::class, options: ['default' => 'prospect'])]
    protected CustomerStatusEnum $status = CustomerStatusEnum::Prospect;

    /**
     * Free text rather than an enum: SARL, SAS and EI cover most of it, but
     * an association, a profession libérale or a foreign company are all
     * legitimate counterparties, and a closed list would have to be edited
     * before one of them could be recorded.
     */
    #[ORM\Column(length: 60, nullable: true)]
    protected ?string $legalForm = null;

    /**
     * Capital social in cents.
     *
     * Cents, not a decimal or a string: a capital printed into a contract is
     * an amount, and rounding one that was stored as a float is the kind of
     * error nobody notices until it is in a signed document.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $shareCapitalCents = null;

    /** Null exactly when there is no capital recorded. */
    #[ORM\Column(length: 3, nullable: true, enumType: CurrencyEnum::class)]
    protected ?CurrencyEnum $shareCapitalCurrency = null;

    /** Siège social, as one block, because that is how it prints. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $registeredOffice = null;

    /**
     * Fourteen digits, stored without separators.
     *
     * Unique so the same company cannot be recorded twice under two spellings
     * of its name - the mistake that makes a customer list stop being an
     * answer to "have we worked with them". Nullable and unique work together
     * here: PostgreSQL treats nulls as distinct, so any number of customers
     * can wait without a SIRET.
     */
    #[ORM\Column(length: 14, unique: true, nullable: true)]
    protected ?string $siret = null;

    /** RCS: the registry city and number, as one field ("Lyon B 123 456 789"). */
    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $tradeRegister = null;

    /** TVA intracommunautaire. */
    #[ORM\Column(length: 30, nullable: true)]
    protected ?string $vatNumber = null;

    /** Feeds the preamble: "Le Client exerce une activité de …". */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $activitySector = null;

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $representativeFirstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $representativeLastName = null;

    /** "agissant en qualité de …" - Gérant, Président, Directeur. */
    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $representativeRole = null;

    /**
     * The contractual address, and the one a signing link is mailed to.
     *
     * Deliberately not the representative's personal mailbox: the contracts
     * name it as the channel that counts, so it has to be the address the
     * company agreed to be reached at.
     *
     * **Nullable since prospects exist.** It was required, on the reasoning
     * that a company you work with is a company you can write to - which is
     * true of a client and not of a prospect: you can meet somebody, open a
     * space to start structuring the work, and have nothing but a name. A
     * space's access links carry their own recipient, so nothing about that
     * screen needs this column.
     *
     * A client is another matter, and the Manager enforces it: this is where
     * their contract is sent, so it is required the moment the status says
     * they have engaged.
     */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $contractualEmail = null;

    /**
     * Le portable, et c'est ce que le champ a toujours été.
     *
     * Son exemple à l'écran est un numéro mobile depuis le premier jour, et
     * c'est celui qu'on compose. Le fixe est la colonne d'à côté plutôt qu'un
     * second sens donné à celle-ci : un seul champ obligeait à choisir lequel
     * des deux on gardait, et la réponse était toujours « celui-ci ».
     */
    #[ORM\Column(length: 30, nullable: true)]
    protected ?string $phone = null;

    /** Le fixe, quand il y en a un - un standard, un atelier. */
    #[ORM\Column(length: 30, nullable: true)]
    protected ?string $landline = null;

    /**
     * Les neuf chiffres qui identifient l'entreprise.
     *
     * **Ce ne sont pas ceux du SIRET par accident** : un SIRET est ce SIREN
     * suivi des cinq chiffres de l'établissement. Les deux colonnes existent
     * quand même, parce qu'une entreprise se connaît souvent par son SIREN
     * bien avant qu'on sache de quel établissement on parle, et que le déduire
     * silencieusement d'un SIRET reviendrait à inventer une saisie.
     *
     * Quand les deux sont remplis, ils doivent s'accorder, et c'est la saisie
     * qui le vérifie : deux numéros qui se contredisent sur la même ligne sont
     * pires qu'un seul.
     */
    #[ORM\Column(length: 9, nullable: true)]
    protected ?string $siren = null;

    /**
     * Les adresses du client : son site, ses réseaux, ce qu'il publie.
     *
     * **Ce ne sont pas les ressources d'un espace.** Celles-là vivent sur
     * l'espace et se montrent ou se cachent une par une ; celles-ci sont au
     * client, les suivent d'un projet à l'autre, et disent simplement où on le
     * trouve. Deux propriétaires, deux durées de vie, deux colonnes.
     *
     * Une liste de `{label, url}` plutôt que deux colonnes de plus : leur
     * nombre n'est pas connu, et rien ne les interroge - elles s'affichent.
     *
     * @var list<array{label: string, url: string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    protected array $links = [];

    /**
     * Ce qu'on note sur ce client et qui n'entre dans aucune case.
     *
     * **Visible par le client**, comme le reste de la fiche, et l'écran le dit
     * sous le champ. Ce qui ne doit pas l'être a déjà son endroit : une note
     * d'espace, que le client ne voit jamais.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $informationNotes = null;

    /**
     * The account this customer signs in with, when they have one.
     *
     * Nullable because most never will: signing a contract needs no account
     * (that is the whole point of the link), and a customer that exists only
     * as a name on a contract is the normal case. The relation is here for the
     * ones who do get a login later, so their sessions and their contracts
     * are known to be the same company.
     *
     * `SET NULL` on delete: removing an account must not remove the company's
     * accounting history with it.
     */
    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $user = null;

    abstract public function getId(): ?int;

    public function getLegalName(): string
    {
        return $this->legalName;
    }

    public function setLegalName(string $legalName): static
    {
        $this->legalName = $legalName;

        return $this;
    }

    public function getLegalForm(): ?string
    {
        return $this->legalForm;
    }

    public function setLegalForm(?string $legalForm): static
    {
        $this->legalForm = $legalForm;

        return $this;
    }

    public function getShareCapitalCents(): ?int
    {
        return $this->shareCapitalCents;
    }

    public function setShareCapitalCents(?int $shareCapitalCents): static
    {
        $this->shareCapitalCents = $shareCapitalCents;

        return $this;
    }

    public function getShareCapitalCurrency(): ?CurrencyEnum
    {
        return $this->shareCapitalCurrency;
    }

    public function setShareCapitalCurrency(?CurrencyEnum $shareCapitalCurrency): static
    {
        $this->shareCapitalCurrency = $shareCapitalCurrency;

        return $this;
    }

    public function getRegisteredOffice(): ?string
    {
        return $this->registeredOffice;
    }

    public function setRegisteredOffice(?string $registeredOffice): static
    {
        $this->registeredOffice = $registeredOffice;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = $siret;

        return $this;
    }

    public function getTradeRegister(): ?string
    {
        return $this->tradeRegister;
    }

    public function setTradeRegister(?string $tradeRegister): static
    {
        $this->tradeRegister = $tradeRegister;

        return $this;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): static
    {
        $this->vatNumber = $vatNumber;

        return $this;
    }

    public function getActivitySector(): ?string
    {
        return $this->activitySector;
    }

    public function setActivitySector(?string $activitySector): static
    {
        $this->activitySector = $activitySector;

        return $this;
    }

    public function getRepresentativeFirstName(): ?string
    {
        return $this->representativeFirstName;
    }

    public function setRepresentativeFirstName(?string $representativeFirstName): static
    {
        $this->representativeFirstName = $representativeFirstName;

        return $this;
    }

    public function getRepresentativeLastName(): ?string
    {
        return $this->representativeLastName;
    }

    public function setRepresentativeLastName(?string $representativeLastName): static
    {
        $this->representativeLastName = $representativeLastName;

        return $this;
    }

    public function getRepresentativeRole(): ?string
    {
        return $this->representativeRole;
    }

    public function setRepresentativeRole(?string $representativeRole): static
    {
        $this->representativeRole = $representativeRole;

        return $this;
    }

    public function getStatus(): CustomerStatusEnum
    {
        return $this->status;
    }

    public function setStatus(CustomerStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isProspect(): bool
    {
        return $this->status->isProspect();
    }

    public function getContractualEmail(): ?string
    {
        return $this->contractualEmail;
    }

    public function setContractualEmail(?string $contractualEmail): static
    {
        $this->contractualEmail = $contractualEmail;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getLandline(): ?string
    {
        return $this->landline;
    }

    public function setLandline(?string $landline): static
    {
        $this->landline = $landline;

        return $this;
    }

    public function getSiren(): ?string
    {
        return $this->siren;
    }

    public function setSiren(?string $siren): static
    {
        $this->siren = $siren;

        return $this;
    }

    /** @return list<array{label: string, url: string}> */
    public function getLinks(): array
    {
        return $this->links;
    }

    /** @param list<array{label: string, url: string}> $links */
    public function setLinks(array $links): static
    {
        $this->links = $links;

        return $this;
    }

    public function getInformationNotes(): ?string
    {
        return $this->informationNotes;
    }

    public function setInformationNotes(?string $informationNotes): static
    {
        $this->informationNotes = $informationNotes;

        return $this;
    }

    public function getUser(): ?CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(?CoreUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRepresentativeFullName(): ?string
    {
        $parts = [];

        foreach ([$this->representativeFirstName, $this->representativeLastName] as $part) {
            $part = mb_trim((string) $part);

            if ('' !== $part) {
                $parts[] = $part;
            }
        }

        return [] === $parts ? null : implode(' ', $parts);
    }
}
