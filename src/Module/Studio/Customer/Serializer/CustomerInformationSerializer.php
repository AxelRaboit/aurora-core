<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Serializer;

use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A customer's sheet, in the shape both sides read.
 *
 * **A single shape for the studio and for the customer**, because that is
 * what lets the summary be the same component on both sides. The studio sees
 * a form above it; what the customer sees is exactly what the studio sees
 * below, and not a second version that could differ from it without anyone
 * noticing.
 *
 * Nothing contractual travels: no capital, no RCS, no VAT, no representative.
 * Those are a contract's mentions, they live on the customer sheet, and a
 * project's page has no business carrying them.
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
            // `email` and not `contractualEmail`: the column is named that way
            // because it is where a signing link goes, but on this sheet it is
            // the customer's address, and naming it after a use that is not
            // the screen's confuses whoever reads it.
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
