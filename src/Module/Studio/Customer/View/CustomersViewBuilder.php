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
        private PathTemplateGenerator $pathTemplateGenerator,
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
            // Each customer's page: the list no longer edits, it leads there.
            'showPath' => $this->pathTemplateGenerator->generate('suite_studio_customers_show', ['id' => '__id__']),
            'convertPath' => $this->pathTemplateGenerator->generate('suite_studio_customers_convert', ['id' => '__id__']),
            'deletePath' => $this->pathTemplateGenerator->generate('suite_studio_customers_delete', ['id' => '__id__']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function customers(): array
    {
        // Their spaces along with them, the ones the reader sees: a customer
        // sheet did not say which projects were running for them.
        $spacesByCustomer = [];
        foreach ($this->studioContext->areSpacesEnabled() ? $this->visibility->visibleSpaces() : [] as $space) {
            $spacesByCustomer[(int) $space->getCustomer()->getId()][] = [
                'id' => $space->getId(),
                'name' => $space->getName(),
                'archived' => $space->isArchived(),
                'url' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]),
            ];
        }

        // Their contracts too, as a count, and the contracts list filtered on
        // them in one click: the sheet said nothing of what had been signed.
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
     * A customer's page: their whole sheet in a form, and what surrounds it
     * (their spaces, contracts, Studio deliverables) read-only.
     *
     * **The only place where the sheet is written.** It had two forms that did
     * not carry the same fields, the list's and the one of a space's
     * Informations tab; the latter now only shows it and leads here.
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
            'convertPath' => $this->pathTemplateGenerator->generate('suite_studio_customers_convert', ['id' => '__id__']),
            'deletePath' => $this->pathTemplateGenerator->generate('suite_studio_customers_delete', ['id' => '__id__']),
            // The spaces list filtered on them, and the contracts one: their
            // complete lists, beyond what the page summarizes.
            'spacesPath' => $this->studioContext->areSpacesEnabled() && $this->authorizationChecker->isGranted('studio.spaces.view')
                ? $this->urlGenerator->generate('suite_studio_spaces', ['customer' => $id])
                : null,
            'contractsPath' => $this->studioContext->areContractsEnabled() && $this->authorizationChecker->isGranted('studio.contracts.view')
                ? $this->urlGenerator->generate('suite_studio_contracts', ['customer' => $id])
                : null,
        ];
    }

    /**
     * What the save from the page answers: the sheet read again.
     *
     * Read again rather than echoed from the input: a SIRET's digits are
     * normalized on the way, and a screen keeping what was typed would show
     * spaces the database does not have.
     *
     * @return array<string, mixed>
     */
    public function showPayload(CustomerInterface $customer): array
    {
        return ['customer' => $this->customerSerializer->serialize($customer)];
    }
}
