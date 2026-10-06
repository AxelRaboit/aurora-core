<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Core\Storage\Probe\StorageUsageProbe;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\CustomerSpace\Serializer\CustomerSpaceSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use DateTimeZone;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class CustomerSpacesViewBuilder
{
    public function __construct(
        private CustomerSpaceSerializerInterface $spaceSerializer,
        private CustomerRepository $customerRepository,
        private UserRepository $userRepository,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
        private StorageUsageProbe $storageUsage,
        private SpaceVisibility $visibility,
        private SpaceWorkload $workload,
        private AuthorizationCheckerInterface $authorizationChecker,
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
            // What each space has had uploaded, in one query. Without this
            // number, "which customer fills my disk" is answered by opening
            // the spaces one by one, so it is not answered.
            'storage' => $this->storageUsage->bySpace(),
            ...$this->formOptions(),
            'boardPath' => $this->pathTemplates->generate('workspace_space_content', ['id' => '__id__']),
            'createPath' => $this->urlGenerator->generate('suite_studio_spaces_create'),
            'spacesPath' => $this->urlGenerator->generate('suite_studio_spaces'),
            'calendarPath' => $this->urlGenerator->generate('suite_studio_spaces_calendar'),
            'convertPath' => $this->pathTemplates->generate('suite_studio_customers_convert', ['id' => '__id__']),
            'deletePath' => $this->pathTemplates->generate('suite_studio_spaces_delete', ['id' => '__id__']),
        ];
    }

    /**
     * What the space form offers, for the creation modal of the list and the
     * Settings tab of a space: the customers, the accounts, the statuses, the
     * roles and the timezones.
     *
     * @return array{customers: list<array{id: int, name: string}>, canCreateCustomer: bool, users: list<array{id: int, name: string, email: string}>, statuses: list<array{value: string, labelKey: string}>, roles: list<array{value: string, labelKey: string}>, timezones: list<string>}
     */
    public function formOptions(): array
    {
        return [
            'customers' => $this->customerOptions(),
            // Opening a space for someone unknown creates their customer
            // sheet: the form only offers this path to whoever can create a
            // customer.
            'canCreateCustomer' => $this->authorizationChecker->isGranted('studio.customers.create'),
            'users' => $this->userOptions(),
            'statuses' => $this->statusOptions(),
            'roles' => $this->roleOptions(),
            // The whole list, as the calendar's own screen does it: a
            // shortlist would be right until the first client abroad.
            'timezones' => DateTimeZone::listIdentifiers(),
        ];
    }

    /**
     * The Settings tab of a space: the space as the form edits it, the form's
     * options, and where to save it.
     *
     * Null for a reader without `studio.spaces.edit`: the tab then only shows
     * to the lead for the Drive, and the account and customer lists are not
     * read on every opening of a space for nothing. `canConfigure` says
     * whether the team and roles (and the Drive) are the reader's to change,
     * the same rule `CustomerSpaceManager::refuseTeamChangeUnlessLead()`
     * enforces on save.
     *
     * Saved through `suite_studio_spaces_update`, the route the list's modal
     * used: the same input, validation, rights and manager path.
     *
     * @return array{spaceSettings: array<string, mixed>|null}
     */
    public function settingsView(CustomerSpaceInterface $space): array
    {
        if (!$this->authorizationChecker->isGranted('studio.spaces.edit')) {
            return ['spaceSettings' => null];
        }

        return ['spaceSettings' => [
            'space' => $this->spaceSerializer->serialize($space),
            'canConfigure' => $this->visibility->canConfigure($space),
            ...$this->formOptions(),
            'updatePath' => $this->urlGenerator->generate('suite_studio_spaces_update', ['id' => $space->getId()]),
        ]];
    }

    /** @return list<array<string, mixed>> */
    public function spaces(): array
    {
        // Their own, or all for an administrator: the rule lives in
        // `SpaceVisibility`, not here, because three screens ask it.
        // `canConfigure` per row: a space's team and roles are its lead's
        // business, and the form shows them read-only to the others rather
        // than letting the server refuse afterwards.
        //
        // `workload`: what is waiting in each space, counted by
        // `SpaceWorkload` as on the dashboard, in one query for the whole
        // list. Null for an archived space, whose work is done.
        $spaces = $this->visibility->visibleSpaces();
        $workload = [];
        foreach ($this->workload->forSpaces($spaces) as $row) {
            $workload[$row->spaceId] = $row->toArray();
        }

        return array_map(
            fn (CustomerSpaceInterface $space): array => [
                ...$this->spaceSerializer->serialize($space),
                'canConfigure' => $this->visibility->canConfigure($space),
                'workload' => $workload[(int) $space->getId()] ?? null,
            ],
            $spaces,
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
     * Suite accounts, not front ones: a space's members are the studio's own
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
