<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\View;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\Customer\Serializer\CustomerSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class CustomersViewBuilder
{
    public function __construct(
        private CustomerRepository $customerRepository,
        private CustomerSerializerInterface $customerSerializer,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
        private SpaceVisibility $visibility,
        private StudioContext $studioContext,
        private ContractRepository $contractRepository,
        private AuthorizationCheckerInterface $authorizationChecker,
        private CustomerRelatedViewBuilder $relatedViewBuilder,
    ) {}

    /**
     * The whole list, filtered in the page.
     *
     * Not paginated, and that is a sizing decision rather than an oversight: a
     * customer list is read to find one company by name, and one that fits in
     * a few hundred rows answers faster from memory than from a round trip per
     * keystroke. The paginated shape is one repository method away the day a
     * deployment outgrows it.
     *
     * @return array<string, mixed>
     */
    public function indexView(): array
    {
        return [
            'customers' => $this->customers(),
            'currencies' => $this->currencyOptions(),
            'createPath' => $this->urlGenerator->generate('suite_studio_customers_create'),
            // La page de chaque client : la liste ne modifie plus, elle y mène.
            'showPath' => $this->pathTemplates->generate('suite_studio_customers_show', ['id' => '__id__']),
            'convertPath' => $this->pathTemplates->generate('suite_studio_customers_convert', ['id' => '__id__']),
            'deletePath' => $this->pathTemplates->generate('suite_studio_customers_delete', ['id' => '__id__']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function customers(): array
    {
        // Ses espaces avec lui, ceux que le lecteur voit : une fiche client
        // ne disait pas quels projets tournaient pour lui.
        $spacesByCustomer = [];
        foreach ($this->studioContext->areSpacesEnabled() ? $this->visibility->visibleSpaces() : [] as $space) {
            $spacesByCustomer[(int) $space->getCustomer()->getId()][] = [
                'id' => $space->getId(),
                'name' => $space->getName(),
                'archived' => $space->isArchived(),
                'url' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]),
            ];
        }

        // Ses contrats aussi, en nombre, et la liste des contrats filtrée sur
        // lui d'un clic : la fiche ne disait rien de ce qui avait été signé.
        $contractsShown = $this->studioContext->areContractsEnabled() && $this->authorizationChecker->isGranted('studio.contracts.view');
        $contractCounts = $contractsShown ? $this->contractRepository->countByCustomer() : [];

        return array_map(
            fn (CustomerInterface $customer): array => [
                ...$this->customerSerializer->serialize($customer),
                'spaces' => $spacesByCustomer[(int) $customer->getId()] ?? [],
                'contracts' => $contractsShown ? [
                    'count' => $contractCounts[(int) $customer->getId()] ?? 0,
                    'url' => $this->urlGenerator->generate('suite_studio_contracts', ['customer' => $customer->getId()]),
                ] : null,
            ],
            $this->customerRepository->findAllOrdered(),
        );
    }

    /** @return list<array{value: string, symbol: string}> */
    private function currencyOptions(): array
    {
        return array_map(
            static fn (CurrencyEnum $currency): array => [
                'value' => $currency->value,
                'symbol' => $currency->symbol(),
            ],
            CurrencyEnum::cases(),
        );
    }

    /** @return array<string, mixed> */
    public function listPayload(): array
    {
        return ['success' => true, 'customers' => $this->customers()];
    }

    /** @return array<string, mixed> */
    public function customerPayload(CustomerInterface $customer): array
    {
        return [
            'customer' => $this->customerSerializer->serialize($customer),
            'customers' => $this->customers(),
        ];
    }

    /**
     * La page d'un client : toute sa fiche dans un formulaire, et ce qui
     * l'entoure (ses espaces, ses contrats, ses livrables de Studio) en
     * lecture.
     *
     * **Le seul endroit où la fiche s'écrit.** Elle avait deux formulaires qui
     * ne portaient pas les mêmes champs, celui de la liste et celui de
     * l'onglet Informations d'un espace ; ce dernier ne fait plus que la
     * montrer et mener ici.
     *
     * @return array<string, mixed>
     */
    public function showView(CustomerInterface $customer): array
    {
        $id = $customer->getId();

        return [
            'customer' => $this->customerSerializer->serialize($customer),
            'related' => $this->relatedViewBuilder->related($customer),
            'currencies' => $this->currencyOptions(),
            'indexPath' => $this->urlGenerator->generate('suite_studio_customers'),
            'updatePath' => $this->urlGenerator->generate('suite_studio_customers_update', ['id' => $id]),
            'convertPath' => $this->pathTemplates->generate('suite_studio_customers_convert', ['id' => '__id__']),
            'deletePath' => $this->pathTemplates->generate('suite_studio_customers_delete', ['id' => '__id__']),
            // La liste des espaces filtrée sur lui, et celle des contrats :
            // ses listes complètes, au-delà de ce que la page résume.
            'spacesPath' => $this->studioContext->areSpacesEnabled() && $this->authorizationChecker->isGranted('studio.spaces.view')
                ? $this->urlGenerator->generate('suite_studio_spaces', ['customer' => $id])
                : null,
            'contractsPath' => $this->studioContext->areContractsEnabled() && $this->authorizationChecker->isGranted('studio.contracts.view')
                ? $this->urlGenerator->generate('suite_studio_contracts', ['customer' => $id])
                : null,
        ];
    }

    /**
     * Ce que répond l'enregistrement depuis la page : la fiche relue.
     *
     * Relue plutôt que renvoyée depuis la saisie : les chiffres d'un SIRET
     * sont normalisés en chemin, et un écran qui garderait ce qui a été tapé
     * afficherait des espaces que la base n'a pas.
     *
     * @return array<string, mixed>
     */
    public function showPayload(CustomerInterface $customer): array
    {
        return ['customer' => $this->customerSerializer->serialize($customer)];
    }
}
