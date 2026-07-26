<?php

declare(strict_types=1);

namespace Aurora\Module\General\Profile\View;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;

use function in_array;

/**
 * Builds the Twig payload for the admin profile page. Currently exposes the
 * mood-message length cap, kept as a service so future profile widgets can
 * grow without re-introducing payload logic in the controller.
 */
final readonly class ProfileViewBuilder
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexView(User $user): array
    {
        return [
            'moodMessageMaxLength' => User::MOOD_MESSAGE_MAX_LENGTH,
            'canDeleteAccount' => !$this->isLastDevOfType($user),
            'accountInfo' => $this->accountInfo($user),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function accountInfo(User $user): array
    {
        $roles = $user->getRoles();
        $primaryRole = match (true) {
            in_array(UserRoleEnum::Dev->value, $roles, true) => 'dev',
            in_array(UserRoleEnum::Admin->value, $roles, true) => 'admin',
            in_array(UserRoleEnum::User->value, $roles, true) => 'user',
            default => null,
        };

        return [
            'reference' => $user->getReference(),
            'role' => $primaryRole,
            'type' => $user->getType()->value,
            'status' => $user->getStatus()->value,
            'manager' => $user->getManager()?->getName(),
            'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    private function isLastDevOfType(User $user): bool
    {
        if (!in_array(UserRoleEnum::Dev->value, $user->getRoles(), true)) {
            return false;
        }

        return 1 === $this->userRepository->countByRoleAndType(UserRoleEnum::Dev->value, $user->getType());
    }
}
