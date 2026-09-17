<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Serializer\CustomerSpaceSerializerInterface;
use DateTimeZone;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CustomerSpacesViewBuilder
{
    public function __construct(
        private CustomerSpaceRepository $spaceRepository,
        private CustomerSpaceSerializerInterface $spaceSerializer,
        private CustomerRepository $customerRepository,
        private UserRepository $userRepository,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * The whole list, filtered in the page.
     *
     * Same sizing decision the customer list documents: a studio has spaces in
     * the tens, and a list that fits in memory answers a search faster than a
     * round trip per keystroke. The paginated shape is one repository method
     * away the day that stops being true.
     *
     * @return array<string, mixed>
     */
    public function indexView(): array
    {
        return [
            'spaces' => $this->spaces(),
            'customers' => $this->customerOptions(),
            'users' => $this->userOptions(),
            'statuses' => $this->statusOptions(),
            'roles' => $this->roleOptions(),
            // The whole list, as the calendar's own screen does it: a
            // shortlist would be right until the first client abroad.
            'timezones' => DateTimeZone::listIdentifiers(),
            'boardPath' => $this->pathTemplates->generate('workspace_space_content', ['id' => '__id__']),
            'createPath' => $this->urlGenerator->generate('backend_studio_spaces_create'),
            'updatePath' => $this->pathTemplates->generate('backend_studio_spaces_update', ['id' => '__id__']),
            'convertPath' => $this->pathTemplates->generate('backend_studio_customers_convert', ['id' => '__id__']),
            'deletePath' => $this->pathTemplates->generate('backend_studio_spaces_delete', ['id' => '__id__']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function spaces(): array
    {
        return array_map(
            $this->spaceSerializer->serialize(...),
            $this->spaceRepository->findAllOrdered(),
        );
    }

    /**
     * The companies a space can be opened for.
     *
     * Id and legal name only: the picker has to let somebody find a company
     * they know the name of, and nothing else about a customer belongs on a
     * page about work.
     *
     * @return list<array{id: int, name: string}>
     */
    private function customerOptions(): array
    {
        return array_map(
            static fn (CustomerInterface $customer): array => [
                'id' => (int) $customer->getId(),
                'name' => $customer->getLegalName(),
            ],
            $this->customerRepository->findAllOrdered(),
        );
    }

    /**
     * The accounts that can be put on a space.
     *
     * Backend accounts, not front ones: a space's members are the studio's own
     * people. The client's own login, when they have one, is attached to the
     * customer record and answers a different question.
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
            $this->userRepository->findAllAdminsAlphabetical(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function statusOptions(): array
    {
        return array_map(
            static fn (CustomerSpaceStatusEnum $status): array => [
                'value' => $status->value,
                'labelKey' => $status->getLabelKey(),
            ],
            CustomerSpaceStatusEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function roleOptions(): array
    {
        return array_map(
            static fn (CustomerSpaceMemberRoleEnum $role): array => [
                'value' => $role->value,
                'labelKey' => $role->getLabelKey(),
            ],
            CustomerSpaceMemberRoleEnum::cases(),
        );
    }

    /** @return array<string, mixed> */
    public function listPayload(): array
    {
        return ['success' => true, 'spaces' => $this->spaces()];
    }

    /** @return array<string, mixed> */
    public function spacePayload(CustomerSpaceInterface $space): array
    {
        return [
            'space' => $this->spaceSerializer->serialize($space),
            'spaces' => $this->spaces(),
        ];
    }
}
