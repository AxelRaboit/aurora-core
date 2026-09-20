<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\View;

use Aurora\Module\Studio\Customer\Serializer\CustomerInformationSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * La fiche du client, envoyée avec la page de son espace.
 *
 * **La fiche est au client, pas à l'espace**, et c'est le point : un SIRET
 * appartient à une société et non à un projet. Deux espaces ouverts pour le
 * même client montrent donc la même fiche, se modifient au même endroit, et ne
 * peuvent pas se contredire - ce qu'une copie par espace aurait garanti dès le
 * deuxième projet.
 */
final readonly class SpaceInformationViewBuilder
{
    public function __construct(
        private CustomerInformationSerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function view(CustomerSpaceInterface $space): array
    {
        return [
            'information' => $this->serializer->serialize($space->getCustomer()),
            'informationSavePath' => $this->urlGenerator->generate('workspace_space_information_save', ['id' => $space->getId()]),
        ];
    }

    /**
     * Ce que renvoie l'enregistrement : la fiche telle qu'elle est en base.
     *
     * Relue plutôt que renvoyée depuis la saisie, pour la raison ordinaire :
     * les chiffres d'un SIRET sont normalisés en chemin, et un écran qui
     * garderait ce qui a été tapé afficherait des espaces que la base n'a pas.
     *
     * @return array<string, mixed>
     */
    public function payload(CustomerSpaceInterface $space): array
    {
        return ['success' => true, 'information' => $this->serializer->serialize($space->getCustomer())];
    }
}
