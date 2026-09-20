<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Serializer;

use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * La fiche d'un client, dans la forme que les deux côtés lisent.
 *
 * **Une seule forme pour le studio et pour le client**, parce que c'est ce qui
 * permet au récapitulatif d'être le même composant des deux côtés. Le studio
 * voit un formulaire au-dessus ; ce que le client voit est exactement ce que
 * le studio voit en dessous, et non une seconde version qui pourrait en
 * différer sans que personne s'en aperçoive.
 *
 * Rien de contractuel ne voyage : ni capital, ni RCS, ni TVA, ni représentant.
 * Ce sont les mentions d'un contrat, elles vivent sur la fiche client, et la
 * page d'un projet n'a pas à les porter.
 */
#[AsAlias(CustomerInformationSerializerInterface::class)]
class CustomerInformationSerializer implements CustomerInformationSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(CustomerInterface $customer): array
    {
        return [
            'legalName' => $customer->getLegalName(),
            'siret' => $customer->getSiret(),
            'siren' => $customer->getSiren(),
            'phone' => $customer->getPhone(),
            'landline' => $customer->getLandline(),
            // `email` et non `contractualEmail` : la colonne s'appelle ainsi
            // parce que c'est là que part un lien de signature, mais sur cette
            // fiche c'est l'adresse du client, et la nommer d'après un usage
            // qui n'est pas celui de l'écran embrouille qui la lit.
            'email' => $customer->getContractualEmail(),
            'postalAddress' => $customer->getRegisteredOffice(),
            'links' => $customer->getLinks(),
            'notes' => $customer->getInformationNotes(),
        ];
    }

    public function hasContent(CustomerInterface $customer): bool
    {
        if (null !== $customer->getSiret()) {
            return true;
        }

        if (null !== $customer->getSiren()) {
            return true;
        }

        if (null !== $customer->getPhone()) {
            return true;
        }

        if (null !== $customer->getLandline()) {
            return true;
        }

        if (null !== $customer->getContractualEmail()) {
            return true;
        }

        if (null !== $customer->getRegisteredOffice()) {
            return true;
        }

        if (null !== $customer->getInformationNotes()) {
            return true;
        }

        return [] !== $customer->getLinks();
    }
}
