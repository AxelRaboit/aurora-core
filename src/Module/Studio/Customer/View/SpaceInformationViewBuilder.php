<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\View;

use Aurora\Module\Studio\Customer\Serializer\CustomerInformationSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * La fiche du client, envoyée avec la page de son espace, en lecture.
 *
 * **La fiche est au client, pas à l'espace**, et c'est le point : un SIRET
 * appartient à une société et non à un projet. Deux espaces ouverts pour le
 * même client montrent donc la même fiche, et ne peuvent pas se contredire.
 *
 * **Elle ne s'écrit plus d'ici.** L'onglet avait son propre formulaire, avec
 * d'autres champs que l'écran des clients : le SIREN, le fixe, les liens et
 * les notes ne se saisissaient que depuis un espace, le capital et le RCS que
 * depuis la liste. Tout se modifie maintenant sur la page du client, et
 * l'onglet y mène, pour qui a le droit de la modifier.
 */
final readonly class SpaceInformationViewBuilder
{
    public function __construct(
        private CustomerInformationSerializerInterface $serializer,
        private CustomerRelatedViewBuilder $related,
        private UrlGeneratorInterface $urlGenerator,
        private AuthorizationCheckerInterface $authorizationChecker,
        private StudioContext $studioContext,
    ) {}

    /** @return array<string, mixed> */
    public function view(CustomerSpaceInterface $space): array
    {
        $customer = $space->getCustomer();

        return [
            'information' => $this->serializer->serialize($customer),
            'related' => $this->related->related($customer, $space),
            'customerPath' => $this->customerPath($space),
        ];
    }

    /**
     * La page du client, ou null pour qui ne pourrait pas y modifier la fiche.
     *
     * Les deux droits, parce que le lien s'appelle « Modifier la fiche » : la
     * page demande de voir les clients, l'enregistrement de les modifier. Un
     * lien vers un formulaire en lecture seule serait une promesse qu'il ne
     * tient pas. Et rien quand le module des clients est éteint : sa page
     * répondrait 404.
     */
    private function customerPath(CustomerSpaceInterface $space): ?string
    {
        if (!$this->studioContext->areCustomersEnabled()) {
            return null;
        }

        if (!$this->authorizationChecker->isGranted('studio.customers.view') || !$this->authorizationChecker->isGranted('studio.customers.edit')) {
            return null;
        }

        return $this->urlGenerator->generate('suite_studio_customers_show', ['id' => $space->getCustomer()->getId()]);
    }
}
