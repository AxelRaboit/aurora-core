<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

use Aurora\Module\Studio\Customer\Validator\Siren;
use Aurora\Module\Studio\Customer\Validator\Siret;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function array_map;
use function str_starts_with;

/**
 * La fiche d'un client, telle qu'on la remplit depuis son espace.
 *
 * **Une saisie à part de celle des clients, et c'est délibéré.** Les deux
 * écrivent dans les mêmes colonnes, mais pas dans les mêmes : celui des
 * clients porte l'identité contractuelle entière - capital, RCS, TVA,
 * représentant - qui n'a rien à faire sur l'écran d'un projet. Réutiliser sa
 * saisie aurait voulu dire poster ces champs à chaque enregistrement, donc les
 * vider au premier oubli.
 *
 * Tout est facultatif sauf le nom, parce que c'est lui que chaque liste, chaque
 * sélecteur et chaque contrat affiche : une fiche sans nom est une ligne qu'on
 * ne retrouve plus.
 */
class CustomerInformationInput implements CustomerInformationInputInterface
{
    /** @param list<CustomerLinkInput> $links */
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.space_information.errors.legal_name_required')]
        #[Assert\Length(max: 180, maxMessage: 'backend.studio.space_information.errors.legal_name_too_long')]
        public readonly string $legalName = '',
        #[Siret]
        public readonly ?string $siret = null,
        #[Siren]
        public readonly ?string $siren = null,
        #[Assert\Length(max: 30, maxMessage: 'backend.studio.space_information.errors.phone_too_long')]
        public readonly ?string $phone = null,
        #[Assert\Length(max: 30, maxMessage: 'backend.studio.space_information.errors.phone_too_long')]
        public readonly ?string $landline = null,
        #[Assert\Email(message: 'backend.studio.space_information.errors.email_invalid')]
        #[Assert\Length(max: 180, maxMessage: 'backend.studio.space_information.errors.email_too_long')]
        public readonly ?string $email = null,
        public readonly ?string $postalAddress = null,
        /**
         * `Valid` est ce qui fait descendre la validation dans chaque ligne.
         * Sans lui, un tableau d'objets est traversé sans que leurs propres
         * contraintes soient lues, et une adresse invalide passerait.
         *
         * @var list<CustomerLinkInput>
         */
        #[Assert\Valid]
        #[Assert\Count(max: 30, maxMessage: 'backend.studio.space_information.errors.links_too_many')]
        public readonly array $links = [],
        #[Assert\Length(max: 5000, maxMessage: 'backend.studio.space_information.errors.notes_too_long')]
        public readonly ?string $notes = null,
    ) {}

    /**
     * Les deux numéros doivent parler de la même entreprise.
     *
     * Un SIRET est le SIREN suivi des cinq chiffres de l'établissement. Quand
     * les deux sont saisis et ne s'accordent pas, l'un des deux est faux et
     * rien ne dit lequel - la fiche porterait deux identités. L'erreur se pose
     * sur le SIREN, qui est le champ ajouté : c'est celui qu'on vient de taper.
     *
     * Chacun garde sa propre clé de contrôle par ailleurs ; ceci ne remplace
     * pas {@see Siret} ni {@see Siren}, cela vérifie leur accord.
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

        $context->buildViolation('backend.studio.space_information.errors.siren_mismatch')
            ->atPath('siren')
            ->addViolation();
    }

    public function getLegalName(): string
    {
        return $this->legalName;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function getSiren(): ?string
    {
        return $this->siren;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getLandline(): ?string
    {
        return $this->landline;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPostalAddress(): ?string
    {
        return $this->postalAddress;
    }

    /**
     * Les liens, sous la forme que la colonne stocke.
     *
     * La conversion se fait ici et pas dans le gestionnaire : la saisie est ce
     * qui connaît la forme de ses propres objets, et l'entité ne doit voir
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }
}
