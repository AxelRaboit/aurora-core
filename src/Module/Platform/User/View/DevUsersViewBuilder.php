<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\View;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeInterface;

/**
 * Builds the Twig payload for the dev users dashboard tab. Centralises the
 * pagination + per-user serialisation shape so the controller stays focused
 * on flow (XHR vs full page, current user awareness).
 */
final readonly class DevUsersViewBuilder
{
    public function __construct(private UserRepository $userRepository) {}

    /**
     * @return array<string, mixed>
     */
    public function usersPayload(int $page, ?string $search, User $currentUser): array
    {
        $result = $this->userRepository->findPaginatedForAdmin($page, $search);

        $items = array_map(
            fn (CoreUserInterface $user): array => [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'locale' => $user->getLocale()->value,
                'isDevRole' => in_array(UserRoleEnum::Dev->value, $user->getRoles(), true),
                // From UserRoleEnum::colour(), like every other role badge: this
                // list used to paint a dev indigo and everyone else grey, which
                // agreed with nothing else in the app.
                'roleColor' => in_array(UserRoleEnum::Dev->value, $user->getRoles(), true)
                    ? UserRoleEnum::Dev->badgeColor()
                    : UserRoleEnum::User->badgeColor(),
                'createdAt' => $user->getCreatedAt()->format(DateTimeInterface::ATOM),
                'isCurrent' => $user->getId() === $currentUser->getId(),
            ],
            $result['items'],
        );

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function indexView(array $payload, ?string $search): array
    {
        return [
            'tab' => 'users',
            'users' => $payload,
            'search' => $search ?? '',
        ];
    }
}
