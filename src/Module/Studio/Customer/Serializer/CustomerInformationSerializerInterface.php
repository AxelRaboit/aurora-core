<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Serializer;

use Aurora\Module\Studio\Customer\Entity\CustomerInterface;

interface CustomerInformationSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(CustomerInterface $customer): array;

    /**
     * Si la fiche dit quelque chose de plus que le nom.
     *
     * Ce que l'onglet du client consulte pour savoir s'il a lieu d'exister :
     * un nom, le client le connaît déjà, il est en haut de la page.
     */
    public function hasContent(CustomerInterface $customer): bool;
}
