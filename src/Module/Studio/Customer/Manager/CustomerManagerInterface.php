<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Manager;

use Aurora\Module\Studio\Customer\Dto\CustomerInformationInputInterface;
use Aurora\Module\Studio\Customer\Dto\CustomerInputInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;

interface CustomerManagerInterface
{
    public function create(CustomerInputInterface $input): CustomerInterface;

    public function update(CustomerInterface $customer, CustomerInputInterface $input): void;

    /**
     * La fiche du client, telle que son espace la remplit.
     *
     * Les colonnes que cet écran ne montre pas ne sont pas touchées : elles
     * portent l'identité contractuelle, qui se remplit sur la fiche client.
     */
    public function updateInformation(CustomerInterface $customer, CustomerInformationInputInterface $input): void;

    /**
     * Un prospect devient client.
     *
     * L'adresse est le seul champ demande, parce que c'est le seul que le
     * statut impose : c'est la que part son contrat. Le reste de l'identite
     * legale se remplit sur sa fiche, quand on l'a.
     */
    public function convertToClient(CustomerInterface $customer, ?string $contractualEmail): void;

    public function delete(CustomerInterface $customer): void;
}
