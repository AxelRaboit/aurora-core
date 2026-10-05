<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\View;

use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Customer\Serializer\CustomerInformationSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_map;
use function array_values;

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
        private ContractRepository $contracts,
        private DeckRepository $decks,
        private SpaceVisibility $visibility,
        private AuthorizationCheckerInterface $authorizationChecker,
        private TranslatorInterface $translator,
        private StudioContext $studioContext,
    ) {}

    /** @return array<string, mixed> */
    public function view(CustomerSpaceInterface $space): array
    {
        return [
            'information' => $this->serializer->serialize($space->getCustomer()),
            'informationSavePath' => $this->urlGenerator->generate('workspace_space_information_save', ['id' => $space->getId()]),
            'related' => $this->related($space),
        ];
    }

    /**
     * Ce qui entoure ce client : ses contrats, ses présentations, ses autres
     * espaces.
     *
     * Rien ne les reliait : un espace ne disait pas quel contrat couvrait le
     * travail, ni quelle présentation l'avait vendu, et il fallait chercher le
     * client dans trois listes. Chaque liste suit les interrupteurs de Studio
     * et les droits du lecteur - null pour ce qu'il ne peut pas ouvrir,
     * plutôt qu'une liste de liens qui répondraient 404 ou 403.
     *
     * @return array{contracts: list<array<string, mixed>>|null, decks: list<array<string, mixed>>|null, spaces: list<array<string, mixed>>}
     */
    private function related(CustomerSpaceInterface $space): array
    {
        $customer = $space->getCustomer();

        return [
            'contracts' => $this->studioContext->areContractsEnabled() && $this->authorizationChecker->isGranted('studio.contracts.view')
                ? array_map(fn (ContractInterface $contract): array => [
                    'label' => $contract->getReference() ?? $this->translator->trans('backend.studio.space_information.draft_contract'),
                    'detail' => $this->translator->trans($contract->getStatus()->getLabel()),
                    'url' => $this->urlGenerator->generate('backend_studio_contracts_show', ['id' => $contract->getId()]),
                ], $this->contracts->findBy(['customer' => $customer], ['id' => 'DESC']))
                : null,
            'decks' => $this->studioContext->areDecksEnabled() && $this->authorizationChecker->isGranted('studio.decks.view')
                ? array_map(fn (DeckInterface $deck): array => [
                    'label' => $deck->getTitle(),
                    'detail' => null,
                    'url' => $this->urlGenerator->generate('backend_studio_deck', ['id' => $deck->getId()]),
                ], $this->decks->findLiveForCustomer($customer))
                : null,
            'spaces' => array_values(array_map(fn (CustomerSpaceInterface $other): array => [
                'label' => $other->getName(),
                'detail' => $other->isArchived() ? $this->translator->trans('backend.studio.spaces.archived_badge') : null,
                'url' => $this->urlGenerator->generate('workspace_space_content', ['id' => $other->getId()]),
            ], array_filter(
                $this->visibility->visibleSpaces(),
                static fn (CustomerSpaceInterface $other): bool => $other->getId() !== $space->getId() && $other->getCustomer()->getId() === $customer->getId(),
            ))),
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
