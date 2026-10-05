<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Security\Suite;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void {}

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (UserStatusEnum::PendingVerification === $user->getStatus()) {
            throw new CustomUserMessageAccountStatusException('suite.errors.email_not_verified');
        }

        if (UserStatusEnum::Disabled === $user->getStatus()) {
            throw new CustomUserMessageAccountStatusException('suite.errors.account_disabled');
        }
    }
}
