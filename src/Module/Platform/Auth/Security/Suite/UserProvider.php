<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Security\Suite;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Resolves admin/suite users only (type = admin).
 *
 * @implements UserProviderInterface<CoreUserInterface>
 */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(private UserRepository $userRepository) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findOneBy(['email' => $identifier, 'type' => UserTypeEnum::Suite]);
        if (null === $user) {
            throw new UserNotFoundException(sprintf('Admin user "%s" not found.', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
