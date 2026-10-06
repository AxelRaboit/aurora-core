<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Manager;

use Aurora\Module\Studio\Customer\Dto\CustomerInputInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;

interface CustomerManagerInterface
{
    public function create(CustomerInputInterface $input): CustomerInterface;

    /**
     * La fiche entiere, depuis la page du client : le seul chemin d'ecriture.
     *
     * L'onglet Informations d'un espace ne l'ecrit plus, il la montre et mene
     * ici. Une saisie entiere s'applique entiere : un champ absent est vide.
     */
    public function update(CustomerInterface $customer, CustomerInputInterface $input): void;

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
