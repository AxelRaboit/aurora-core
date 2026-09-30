<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\View;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
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
        private UserRepository $userRepository,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
        private SpaceVisibility $visibility,
        private StudioContext $studioContext,
        private ContractRepository $contractRepository,
        private AuthorizationCheckerInterface $authorizationChecker,
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
            'users' => $this->userOptions(),
            'currencies' => $this->currencyOptions(),
            'createPath' => $this->urlGenerator->generate('backend_studio_customers_create'),
            'updatePath' => $this->pathTemplates->generate('backend_studio_customers_update', ['id' => '__id__']),
            'convertPath' => $this->pathTemplates->generate('backend_studio_customers_convert', ['id' => '__id__']),
            'deletePath' => $this->pathTemplates->generate('backend_studio_customers_delete', ['id' => '__id__']),
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
                    'url' => $this->urlGenerator->generate('backend_studio_contracts', ['customer' => $customer->getId()]),
                ] : null,
            ],
            $this->customerRepository->findAllOrdered(),
        );
    }

    /**
     * The accounts a customer can be attached to.
     *
     * Id, name and email only. The picker has to let someone tell two people
     * called Martin apart, and nothing else about an account belongs on a page
     * about companies.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    private function userOptions(): array
    {
        return array_map(
            static fn (CoreUserInterface $user): array => [
                'id' => (int) $user->getId(),
                'name' => $user instanceof User ? $user->getName() : $user->getUserIdentifier(),
                'email' => $user->getUserIdentifier(),
            ],
            $this->userRepository->findAllFrontUsersAlphabetical(),
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

    public function customerPayload(CustomerInterface $customer): array
    {
        return [
            'customer' => $this->customerSerializer->serialize($customer),
            'customers' => $this->customers(),
        ];
    }
}
