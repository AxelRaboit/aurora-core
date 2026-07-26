<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Serializer;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Service\UserProfilePhotoUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use const DATE_ATOM;

#[AsAlias(UserSerializerInterface::class)]
class UserSerializer implements UserSerializerInterface
{
    public function __construct(
        protected readonly TranslatorInterface $translator,
        protected readonly UserProfilePhotoUrlGenerator $userProfilePhotoUrlGenerator,
    ) {}

    /**
     * Lightweight payload used in paginated lists. Does NOT load the
     * subordinates collection — use {@see serializeWithSubordinates()} for
     * single-user views (e.g. profile detail modal).
     */
    public function serialize(CoreUserInterface $user): array
    {
        // Visible role for the badge. Dev is hidden (shown via isDev flag). ROLE_USER is only
        // shown when it is the user's actual highest role — it is excluded when Admin or Dev is
        // present because getRoles() always appends ROLE_USER via the entity getter.
        $effectivePriority = UserRoleEnum::highestPriorityForRoles($user->getRoles());
        $primaryRole = match (true) {
            $effectivePriority >= UserRoleEnum::Dev->priority() => null,
            $effectivePriority >= UserRoleEnum::Admin->priority() => UserRoleEnum::Admin,
            default => UserRoleEnum::User,
        };

        // Effective priority used by the frontend `canActOn` guard. MUST consider the Dev role,
        // otherwise a Dev user serialises as priority 0 and any admin appears able to edit them.

        $manager = $user->getManager();

        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'role' => $primaryRole?->value,
            'roleLabel' => null === $primaryRole ? null : $this->translator->trans($primaryRole->getLabelKey()),
            'rolePriority' => $effectivePriority,
            'isDev' => in_array(UserRoleEnum::Dev->value, $user->getRoles(), true),
            'type' => $user->getType()->value,
            'typeLabel' => $this->translator->trans($user->getType()->getLabelKey()),
            'status' => $user->getStatus()->value,
            'statusLabel' => $this->translator->trans($user->getStatus()->getLabelKey()),
            'locale' => $user->getLocale()->value,
            'profilePhotoUrl' => $this->userProfilePhotoUrlGenerator->url($user),
            'moodMessage' => $user->getMoodMessage(),
            'moodMessageMaxLength' => User::MOOD_MESSAGE_MAX_LENGTH,
            'managerId' => $manager?->getId(),
            'manager' => $manager instanceof CoreUserInterface ? ['id' => $manager->getId(), 'name' => $manager->getName()] : null,
            'privileges' => $user->getPrivileges(),
            'disabledModules' => $user->getDisabledModules(),
            'invitedAt' => $user->getInvitedAt()?->format(DATE_ATOM),
            'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * Full payload including the subordinates collection. Triggers a lazy
     * load — only call for a single user (detail endpoint), never inside
     * a list loop.
     */
    public function serializeWithSubordinates(CoreUserInterface $user): array
    {
        $subordinates = array_map(
            static fn (CoreUserInterface $subordinate): array => [
                'id' => $subordinate->getId(),
                'name' => $subordinate->getName(),
            ],
            $user->getSubordinates()->toArray(),
        );

        return [
            ...$this->serialize($user),
            'subordinates' => $subordinates,
            'subordinatesCount' => count($subordinates),
        ];
    }
}
