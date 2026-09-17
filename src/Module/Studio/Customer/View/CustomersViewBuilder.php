<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\View;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\Customer\Serializer\CustomerSerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CustomersViewBuilder
{
    public function __construct(
        private CustomerRepository $customerRepository,
        private CustomerSerializerInterface $customerSerializer,
        private UserRepository $userRepository,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
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
        return array_map(
            $this->customerSerializer->serialize(...),
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
