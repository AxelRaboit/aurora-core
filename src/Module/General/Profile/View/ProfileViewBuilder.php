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
        // The enum case first, then everything derived from it. This used to be
        // its own `match (true)` over the role strings, which made it a fifth
        // place deciding which role is the primary one - and the colour a sixth,
        // over in a Vue composable with a map of its own.
        $primaryRole = $this->primaryRole($user->getRoles());

        return [
            'reference' => $user->getReference(),
            // The short name, which is what the label keys are built from.
            'role' => $primaryRole instanceof UserRoleEnum ? mb_strtolower($primaryRole->name) : null,
            'roleColor' => $primaryRole?->badgeColor(),
            'type' => $user->getType()->value,
            'status' => $user->getStatus()->value,
            'manager' => $user->getManager()?->getName(),
            'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * The highest role the user holds, as a case.
     *
     * Reads the priority the enum declares rather than a ladder written here:
     * the order Dev outranks Admin outranks User is one fact, and the badge on
     * the users list already asks the enum for it.
     *
     * @param list<string> $roles
     */
    private function primaryRole(array $roles): ?UserRoleEnum
    {
        $highest = null;
        foreach (UserRoleEnum::cases() as $role) {
            if (!in_array($role->value, $roles, true)) {
                continue;
            }

            if (null === $highest || $role->priority() > $highest->priority()) {
                $highest = $role;
            }
        }

        return $highest;
    }

    private function isLastDevOfType(User $user): bool
    {
        if (!in_array(UserRoleEnum::Dev->value, $user->getRoles(), true)) {
            return false;
        }

        return 1 === $this->userRepository->countByRoleAndType(UserRoleEnum::Dev->value, $user->getType());
    }
}
